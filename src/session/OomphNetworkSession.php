<?php

namespace ethaniccc\Oomph\session;

use ethaniccc\Oomph\utils\ReflectionUtils;
use pocketmine\lang\Translatable;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\encryption\DecryptionException;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\handler\SessionStartPacketHandler;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\PlayerInfo;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\ObjectSet;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\YmlServerProperties;
use pocketmine\lang\KnownTranslationFactory;

class OomphNetworkSession extends NetworkSession {

	private \ReflectionClass $refl;

	private bool $enableCompression = true;

	private bool $isFirstPacket = true;

	public function __construct(Server $server, NetworkSessionManager $manager, PacketPool $packetPool, PacketSerializerContext $packetSerializerContext, PacketSender $sender, PacketBroadcaster $broadcaster, EntityEventBroadcaster $entityEventBroadcaster,Compressor $compressor, TypeConverter $typeConverter, string $ip, int $port
	) {
		parent::__construct($server, $manager, $packetPool, $packetSerializerContext, $sender, $broadcaster, $entityEventBroadcaster, $compressor, $typeConverter, $ip, $port);
		$this->refl = new \ReflectionClass(NetworkSession::class);
	}

	private function getReflProperty(string $property) {
		return $this->refl->getProperty($property)->getValue($this);
	}

	private function onSessionStartSuccess() : void{
		$this->getLogger()->debug("Session start handshake completed, awaiting login packet");
		ReflectionUtils::invoke(NetworkSession::class, $this, "flushSendBuffer", true);
		$this->enableCompression = true;
		$this->setHandler(new LoginPacketHandler(
			Server::getInstance(),
			$this,
			function(PlayerInfo $info) : void{
				ReflectionUtils::setProperty(NetworkSession::class, $this, "info", $info);
				$this->getLogger()->info(Server::getInstance()->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_playerName(TextFormat::AQUA . $info->getUsername() . TextFormat::RESET)));
				$this->getLogger()->setPrefix("NetworkSession: " . $this->getDisplayName());
				ReflectionUtils::getProperty(NetworkSession::class, $this, "manager")->markLoginReceived($this);
			},
			function(bool $authenticated, bool $authRequired, Translatable|string|null $error, ?string $clientPubKey) : void{
				ReflectionUtils::invoke(NetworkSession::class, $this, "setAuthenticationStatus", $authenticated, $authRequired, $error, $clientPubKey);
			},
		));
	}

	public function handleEncoded(string $payload) : void{
		if(!parent::isConnected()){
			return;
		}

		$packetBatchLimiter = $this->getReflProperty("packetBatchLimiter");
		$gamePacketLimiter = $this->getReflProperty("gamePacketLimiter");
		$cipher = $this->getReflProperty("cipher");
		$enableCompression = $this->getReflProperty("enableCompression");
		$packetPool = $this->getReflProperty("packetPool");

		Timings::$playerNetworkReceive->startTiming();
		try{
			$packetBatchLimiter->decrement();

			if($cipher !== null){
				Timings::$playerNetworkReceiveDecrypt->startTiming();
				try{
					$payload = $cipher->decrypt($payload);
				}catch(DecryptionException $e){
					$this->getLogger()->debug("Encrypted packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Packet decryption error");
				}finally{
					Timings::$playerNetworkReceiveDecrypt->stopTiming();
				}
			}

			if($enableCompression && $this->enableCompression){
				Timings::$playerNetworkReceiveDecompress->startTiming();
				try{
					$decompressed = $this->getCompressor()->decompress($payload);
				}catch(DecompressionException $e){
					if($this->isFirstPacket){
						$this->getLogger()->debug("Failed to decompress packet: " . base64_encode($payload));

						$this->enableCompression = false;
						$this->setHandler(new SessionStartPacketHandler(
							$this,
							fn() => $this->onSessionStartSuccess()
						));

						$decompressed = $payload;
					}else{
						$this->getLogger()->debug("Failed to decompress packet: " . base64_encode($payload));
						throw PacketHandlingException::wrap($e, "Compressed packet batch decode error");
					}
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
					$gamePacketLimiter->decrement();
					if(++$count > 2048){ // TODO: Adjustments?
						throw new PacketHandlingException("Too many packets in batch");
					}
					$packet = $packetPool->getPacket($buffer);
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