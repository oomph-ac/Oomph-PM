<?php

namespace ethaniccc\Oomph\session;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\encryption\DecryptionException;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\PacketRateLimiter;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\ObjectSet;

class OomphNetworkSession extends NetworkSession {

	private const INCOMING_PACKET_BATCH_PER_TICK = 2; //usually max 1 per tick, but transactions arrive separately
	private const INCOMING_PACKET_BATCH_BUFFER_TICKS = 100; //enough to account for a 5-second lag spike

	private const INCOMING_GAME_PACKETS_PER_TICK = 2;
	private const INCOMING_GAME_PACKETS_BUFFER_TICKS = 100;

	private PacketRateLimiter $packetBatchLimiter;
	private PacketRateLimiter $gamePacketLimiter;

	private \PrefixedLogger $logger;
	private ?Player $player = null;
	protected ?PlayerInfo $info = null;
	private ?int $ping = null;

	private ?PacketHandler $handler = null;

	private bool $connected = true;
	private bool $disconnectGuard = false;
	protected bool $loggedIn = false;
	private bool $authenticated = false;
	private int $connectTime;
	private ?CompoundTag $cachedOfflinePlayerData = null;

	private ?EncryptionContext $cipher = null;

	/** @var string[] */
	private array $sendBuffer = [];
	/** @var string[] */
	private array $chunkCacheBlobs = [];
	private bool $chunkCacheEnabled = false;

	/**
	 * @var \SplQueue|CompressBatchPromise[]
	 * @phpstan-var \SplQueue<CompressBatchPromise>
	 */
	private \SplQueue $compressedQueue;
	private bool $forceAsyncCompression = true;
	private ?int $protocolId = null;
	protected bool $enableCompression = false; //disabled until handshake completed

	private ?InventoryManager $invManager = null;

	/**
	 * @var \Closure[]|ObjectSet
	 * @phpstan-var ObjectSet<\Closure() : void>
	 */
	private ObjectSet $disposeHooks;

	private Server $server;
	private NetworkSessionManager $manager;
	private PacketPool $packetPool;
	private PacketSerializerContext $packetSerializerContext;
	protected PacketSender $sender;
	private PacketBroadcaster $broadcaster;
	private EntityEventBroadcaster $entityEventBroadcaster;
	private Compressor $compressor;
	private TypeConverter $typeConverter;
	private string $ip = "";
	private int $port = 0;

	/**
	 * @param NetworkSession $parent
	 * @throws \ReflectionException
	 */
	public function __construct(NetworkSession $parent) {
		$ref = new \ReflectionClass($parent);
		$properties = [
			"packetBatchLimiter",
			"gamePacketLimiter",
			"logger",
			"player",
			"info",
			"ping",
			"handler",
			"connected",
			"disconnectGuard",
			"loggedIn",
			"authenticated",
			"connectTime",
			"cachedOfflinePlayerData",
			"cipher",
			"sendBuffer",
			"chunkCacheBlobs",
			"chunkCacheEnabled",
			"compressedQueue",
			"forceAsyncCompression",
			"protocolId",
			"enableCompression",
			"invManager",

			"server",
			"manager",
			"packetPool",
			"packetSerializerContext",
			"sender",
			"broadcaster",
			"entityEventBroadcaster",
			"compressor",
			"typeConverter",
			"ip",
			"port"
		];

		foreach ($properties as $property) {
			$this->{$property} = $ref->getProperty($property)->getValue($parent);
		}
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