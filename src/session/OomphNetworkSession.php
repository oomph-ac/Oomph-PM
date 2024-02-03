<?php

namespace ethaniccc\Oomph\session;

use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\encryption\DecryptionException;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketRateLimiter;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\PacketHandlingException;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;

class OomphNetworkSession extends NetworkSession {

	private const INCOMING_PACKET_BATCH_PER_TICK = 2; //usually max 1 per tick, but transactions arrive separately
	private const INCOMING_PACKET_BATCH_BUFFER_TICKS = 100; //enough to account for a 5-second lag spike

	private const INCOMING_GAME_PACKETS_PER_TICK = 2;
	private const INCOMING_GAME_PACKETS_BUFFER_TICKS = 100;

	private PacketPool $packetPool;
	private PacketRateLimiter $packetBatchLimiter;
	private PacketRateLimiter $gamePacketLimiter;

	private ?EncryptionContext $cipher = null;
	protected bool $enableCompression = false;

	public function __construct(NetworkSession $parent) {
		$ref = new \ReflectionClass($parent);
		$this->packetPool = $ref->getProperty("packetPool")->getValue($parent);
		$this->packetBatchLimiter = new PacketRateLimiter("Packet Batches", self::INCOMING_PACKET_BATCH_PER_TICK, self::INCOMING_PACKET_BATCH_BUFFER_TICKS);
		$this->gamePacketLimiter = new PacketRateLimiter("Game Packets", self::INCOMING_GAME_PACKETS_PER_TICK, self::INCOMING_GAME_PACKETS_BUFFER_TICKS);

		$this->cipher = $ref->getProperty("cipher")->getValue($parent);
		$this->enableCompression = $ref->getProperty("enableCompression")->getValue($parent);

		parent::__construct(
			Server::getInstance(),
			$ref->getProperty("manager")->getValue($parent),
			$this->packetPool,
			$parent->getPacketSerializerContext(),
			$ref->getProperty("sender")->getValue($parent),
			$parent->getBroadcaster(),
			$parent->getEntityEventBroadcaster(),
			$parent->getCompressor(),
			$parent->getTypeConverter(),
			$parent->getIp(),
			$parent->getPort(),
		);
	}

	public function handleEncoded(string $payload) : void{
		if(!$this->isConnected()){
			return;
		}

		Timings::$playerNetworkReceive->startTiming();
		try{
			$this->packetBatchLimiter->decrement();

			if($this->cipher !== null){
				Timings::$playerNetworkReceiveDecrypt->startTiming();
				try{
					$payload = $this->cipher->decrypt($payload);
				}catch(DecryptionException $e){
					$this->getLogger()->debug("Encrypted packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Packet decryption error");
				}finally{
					Timings::$playerNetworkReceiveDecrypt->stopTiming();
				}
			}

			if($this->enableCompression){
				Timings::$playerNetworkReceiveDecompress->startTiming();
				try{
					$decompressed = $this->getCompressor()->decompress($payload);
				}catch(DecompressionException $e){
					$this->getLogger()->debug("Failed to decompress packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Compressed packet batch decode error");
				}finally{
					Timings::$playerNetworkReceiveDecompress->stopTiming();
				}
			}else{
				$decompressed = $payload;
			}

			try{
				$stream = new BinaryStream($decompressed);
				$count = 0;
				foreach(PacketBatch::decodeRaw($stream) as $buffer){
					$this->gamePacketLimiter->decrement();
					if(++$count > 1024){ // TODO: Adjust this limit.
						throw new PacketHandlingException("Too many packets in batch");
					}
					$packet = $this->packetPool->getPacket($buffer);
					if($packet === null){
						$this->getLogger()->debug("Unknown packet: " . base64_encode($buffer));
						throw new PacketHandlingException("Unknown packet received");
					}
					try{
						$this->handleDataPacket($packet, $buffer);
					}catch(PacketHandlingException $e){
						$this->getLogger()->debug($packet->getName() . ": " . base64_encode($buffer));
						throw PacketHandlingException::wrap($e, "Error processing " . $packet->getName());
					}
				}
			}catch(PacketDecodeException|BinaryDataException $e){
				$this->getLogger()->logException($e);
				throw PacketHandlingException::wrap($e, "Packet batch decode error");
			}
		}finally{
			Timings::$playerNetworkReceive->stopTiming();
		}
	}

}