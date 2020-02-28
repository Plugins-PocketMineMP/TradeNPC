<?php
declare(strict_types=1);
namespace TradeNPC;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNBTStream;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\NetworkInventoryAction;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase implements Listener {

	protected $deviceOSData = [];

	private static $instance = null;

	public function onLoad() {
		self::$instance = $this;
	}

	public static function getInstance(): Main {
		return self::$instance;
	}

	public function onEnable() {
		Entity::registerEntity(TradeNPC::class, true, ["tradenpc"]);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function loadData(TradeNPC $npc) {
		if (file_exists($this->getDataFolder() . $npc->getNameTag() . ".dat")) {
			$nbt = (new LittleEndianNBTStream())->read(file_get_contents($this->getDataFolder() . $npc->getNameTag() . ".dat"));
		} else {
			$nbt = new CompoundTag("Offers", [
					new ListTag("Recipes", [])
			]);
		}
		$npc->loadData($nbt);
	}

	public function onMove(PlayerMoveEvent $event) {
		$player = $event->getPlayer();
		foreach ($player->getLevel()->getEntities() as $entity) {
			if ($entity instanceof TradeNPC) {
				if ($player->distance($entity) <= 5) {
					$entity->lookAt($player);
				}
			}
		}
	}

	public function onInteract(PlayerInteractEvent $event) {
		$player = $event->getPlayer();
		if (isset(TradeDataPool::$editNPCData[$player->getName()])) {
			$m = (int) TradeDataPool::$editNPCData[$player->getName()] ["step"];
			$item = $event->getItem();
			if ($m === 1) {
				TradeDataPool::$editNPCData[$player->getName()] ["buy"] = $item;
				TradeDataPool::$editNPCData[$player->getName()] ["step"] = 2;
				$player->sendMessage("판매 아이템으로 터치 해주세요.");
				return;
			}
			if ($m === 2) {
				if (TradeDataPool::$editNPCData[$player->getName()] ["buy"]->equals($item)) {
					$player->sendMessage("구매할 아이템과 판매할 아이템은 같을 수 없습니다.");
					return;
				}
				TradeDataPool::$editNPCData[$player->getName()] ["sell"] = $item;
				TradeDataPool::$editNPCData[$player->getName()] ["step"] = 3;
				$player->sendMessage("엔티티를 터치해주세요.");
				return;
			}
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if (!$sender instanceof Player) {
			return true;
		}
		if (!isset($args[0])) {
			$args[0] = "x";
		}
		switch ($args[0]) {
			case "엔티티생성":
				array_shift($args);
				$name = array_shift($args);
				if (!isset($name)) {
					$sender->sendMessage("엔티티의 이름을 입력해주세요.");
					break;
				}
				$nbt = Entity::createBaseNBT($sender->asVector3());
				$nbt->setTag(new CompoundTag("Skin", [
						new StringTag("Name", $sender->getSkin()->getSkinId()),
						new ByteArrayTag("Data", $sender->getSkin()->getSkinData()),
						new ByteArrayTag("CapeData", $sender->getSkin()->getCapeData()),
						new StringTag("GeometryName", $sender->getSkin()->getGeometryName()),
						new ByteArrayTag("GeometryData", $sender->getSkin()->getGeometryData())
				]));
				/** @var TradeNPC $entity */
				$entity = Entity::createEntity("tradenpc", $sender->getLevel(), $nbt);
				$entity->setNameTag($name);
				$entity->spawnToAll();
				break;
			case "템설정":
				TradeDataPool::$editNPCData[$sender->getName()] = [
						"buy" => null,
						"sell" => null,
						"step" => 1
				];
				$sender->sendMessage("아이템을 들고 터치하세요.");
				$sender->sendMessage("첫번째 터치: 구매");
				$sender->sendMessage("두번째 터치: 판매");
				$sender->sendMessage("터치 작업이 완료되었다면 설정할 엔티티를 터치해주세요.");
				break;
			case "엔티티제거":
				array_shift($args);
				$name = array_shift($args);
				if (!isset($name)) {
					$sender->sendMessage("엔티티의 이름을 입력해주세요.");
					break;
				}
				if (!file_exists($this->getDataFolder() . $name . ".dat")) {
					$sender->sendMessage("해당 이름의 엔티티 데이터파일이 존재하지 않습니다.");
					break;
				}
				unlink($this->getDataFolder() . $name . ".dat");
				$sender->sendMessage("제거되었습니다.");
				foreach ($this->getServer()->getLevels() as $level) {
					foreach ($level->getEntities() as $entity) {
						if ($entity instanceof NPC) {
							if ($entity->getNameTag() === $name) {
								$entity->close();
								break;
							}
						}
					}
				}
				break;
			default:
				foreach ([
								 ["/npc 엔티티생성", "트레이드 인벤토리 엔피시를 생성합니다."],
								 ["/npc 템설정", "트레이드 인벤토리 엔피시의 교환 템을 추가합니다."],
								 ["/npc 엔티티제거", "트레이드 인벤토리 엔피시를 제거합니다."]
						 ] as $usage) {
					$sender->sendMessage($usage[0] . " - " . $usage[1]);
				}
		}
		return true;
	}

	/**
	 * @param DataPacketReceiveEvent $event
	 *
	 * @author
	 */
	public function handleDataPacket(DataPacketReceiveEvent $event) {
		$player = $event->getPlayer();
		$packet = $event->getPacket();
		if ($packet instanceof ActorEventPacket) {
			if ($packet->event === ActorEventPacket::COMPLETE_TRADE) {
				if (!isset(TradeDataPool::$interactNPCData[$player->getName()])) {
					return;
				}
				$data = TradeDataPool::$interactNPCData[$player->getName()]->getShopCompoundTag()->getListTag("Recipes")->get($packet->data);
				if ($data instanceof CompoundTag) {
					$buy = Item::nbtDeserialize($data->getCompoundTag("buyA"));
					$sell = Item::nbtDeserialize($data->getCompoundTag("sell"));
					$player->getInventory()->removeItem($buy);
					$player->getInventory()->addItem($sell);
				}
				unset(TradeDataPool::$interactNPCData[$player->getName()]);
			}
		}
		if ($packet instanceof InventoryTransactionPacket) {
			//7: PC
			if ($packet->transactionType === InventoryTransactionPacket::TYPE_NORMAL) {
				foreach ($packet->actions as $action) {
					if ($action instanceof NetworkInventoryAction) {
						if (isset(TradeDataPool::$windowIdData[$player->getName()]) and $action->windowId === TradeDataPool::$windowIdData[$player->getName()]) {
							//if((int) $this->deviceOSData[strtolower($player->getName())] !== 7) {
								$player->getInventory()->addItem($action->oldItem);
							//}
							$player->getInventory()->removeItem($action->newItem);
						}
					}
				}
			} elseif ($packet->transactionType === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY) {
				$entity = $player->getLevel()->getEntity($packet->trData->entityRuntimeId);
				if ($entity instanceof TradeNPC) {
					if (isset(TradeDataPool::$editNPCData[$player->getName()]) and (int) TradeDataPool::$editNPCData[$player->getName()] ["step"] === 3) {
						/**
						 * @var Item $buy
						 * @var Item $sell
						 */
						$buy = TradeDataPool::$editNPCData[$player->getName()] ["buy"];
						$sell = TradeDataPool::$editNPCData[$player->getName()] ["sell"];
						$entity->addTradeItem($buy, $sell);
						unset(TradeDataPool::$editNPCData[$player->getName()]);
						$player->sendMessage("추가되었습니다.");
					} else {
						if (!isset($this->deviceOSData[strtolower($player->getName())])) {
							$player->sendMessage("서버에 재접속 후 이용해주세요.");
							return;
						}
						if ((int) $this->deviceOSData[strtolower($player->getName())] === 7) {
							$player->sendMessage("Windows10 으로는 이용하실 수 없습니다.");
							return;
						}
						$player->addWindow($entity->getTradeInventory());
					}
				}
			}
		}
		if ($packet instanceof LoginPacket) {
			$device = (int) $packet->clientData["DeviceOS"];
			$this->deviceOSData[strtolower($packet->username)] = $device;
		}
	}

	public function onQuit(PlayerQuitEvent $event) {
		$player = $event->getPlayer();
		if (isset($this->deviceOSData[strtolower($player->getName())])) unset($this->deviceOSData[strtolower($player->getName())]);
	}

	public function saveData(TradeNPC $npc) {
		file_put_contents($this->getDataFolder() . $npc->getNameTag() . ".dat", $npc->getSaveNBT());
	}

	public function onDisable() {
		foreach ($this->getServer()->getLevels() as $level) {
			foreach ($level->getEntities() as $entity) {
				if ($entity instanceof TradeNPC) {
					file_put_contents($this->getDataFolder() . $entity->getNameTag() . ".dat", $entity->getSaveNBT());
				}
			}
		}
	}
}