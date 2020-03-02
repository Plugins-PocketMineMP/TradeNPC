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

class Main extends PluginBase implements Listener
{

	protected $deviceOSData = [];

	private static $instance = null;

	public function onLoad()
	{
		self::$instance = $this;
	}

	public static function getInstance(): Main
	{
		return self::$instance;
	}

	public function onEnable()
	{
		Entity::registerEntity(TradeNPC::class, true, ["tradenpc"]);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function loadData(TradeNPC $npc)
	{
		if (file_exists($this->getDataFolder() . $npc->getNameTag() . ".dat")) {
			$nbt = (new LittleEndianNBTStream())->read(file_get_contents($this->getDataFolder() . $npc->getNameTag() . ".dat"));
		} else {
			$nbt = new CompoundTag("Offers", [
				new ListTag("Recipes", [])
			]);
		}
		$npc->loadData($nbt);
	}

	public function onMove(PlayerMoveEvent $event)
	{
		$player = $event->getPlayer();
		foreach ($player->getLevel()->getEntities() as $entity) {
			if ($entity instanceof TradeNPC) {
				if ($player->distance($entity) <= 5) {
					$entity->lookAt($player);
				}
			}
		}
	}

	public function onInteract(PlayerInteractEvent $event)
	{
		if($event->getAction() == PlayerInteractEvent::RIGHT_CLICK_BLOCK){
		$player = $event->getPlayer();
		if (isset(TradeDataPool::$editNPCData[$player->getName()])) {
			$m = (int)TradeDataPool::$editNPCData[$player->getName()] ["step"];
			$item = $event->getItem();
			if ($m === 1) {
				TradeDataPool::$editNPCData[$player->getName()] ["buy"] = $item;
				TradeDataPool::$editNPCData[$player->getName()] ["step"] = 2;
				$player->sendMessage("Touch item that you want to sell.");
				return;
			}
			if ($m === 2) {
				if (TradeDataPool::$editNPCData[$player->getName()] ["buy"]->equals($item)) {
					$player->sendMessage("The sell item and buy item cannot be equals");
					return;
				}
				TradeDataPool::$editNPCData[$player->getName()] ["sell"] = $item;
				TradeDataPool::$editNPCData[$player->getName()] ["step"] = 3;
				$player->sendMessage("Please interact the npc.");
				return;
			}
		}
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
	{
		if (!$sender instanceof Player) {
			return true;
		}
		if (!isset($args[0])) {
			$args[0] = "x";
		}
		switch ($args[0]) {
			case "createentity":
				array_shift($args);
				$name = array_shift($args);
				if (!isset($name)) {
					$sender->sendMessage("Input the entity's name.");
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
			case "setitem":
				TradeDataPool::$editNPCData[$sender->getName()] = [
					"buy" => null,
					"sell" => null,
					"step" => 1
				];
				$sender->sendMessage("Touch the item");
				$sender->sendMessage("First touch: buy");
				$sender->sendMessage("Second touch: sell");
				$sender->sendMessage("If the touch work is ended, interact the npc.");
				break;
			case "removeentity":
				array_shift($args);
				$name = array_shift($args);
				if (!isset($name)) {
					$sender->sendMessage("Input the entity's name");
					break;
				}
				if (!file_exists($this->getDataFolder() . $name . ".dat")) {
					$sender->sendMessage("The file that npc's data does not exists.");
					break;
				}
				unlink($this->getDataFolder() . $name . ".dat");
				$sender->sendMessage("Removed.");
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
							 ["/npc createentity", "Create an NPC"],
							 ["/npc setitem", "Add the item to NPC"],
							 ["/npc removeentity", "Remove an NPC"]
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
	public function handleDataPacket(DataPacketReceiveEvent $event)
	{
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
							if ($player->getInventory()->contains($action->oldItem)) { // Prevents https://github.com/alvin0319/TradeNPC/issues/3
								$player->getInventory()->addItem($action->oldItem);
								$player->getInventory()->removeItem($action->newItem);
							}
						}
					}
				}
			} elseif ($packet->transactionType === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY) {
				$entity = $player->getLevel()->getEntity($packet->trData->entityRuntimeId);
				if ($entity instanceof TradeNPC) {
					if (isset(TradeDataPool::$editNPCData[$player->getName()]) and (int)TradeDataPool::$editNPCData[$player->getName()] ["step"] === 3) {
						/**
						 * @var Item $buy
						 * @var Item $sell
						 */
						$buy = TradeDataPool::$editNPCData[$player->getName()] ["buy"];
						$sell = TradeDataPool::$editNPCData[$player->getName()] ["sell"];
						$entity->addTradeItem($buy, $sell);
						unset(TradeDataPool::$editNPCData[$player->getName()]);
						$player->sendMessage("Added.");
					} else {
						if (!isset($this->deviceOSData[strtolower($player->getName())])) {
							$player->sendMessage("Please reconnect the server.");
							return;
						}
						if ((int)$this->deviceOSData[strtolower($player->getName())] === 7) {
							$player->sendMessage("You can't use this that you are in Windows 10.");
							return;
						}
						$player->addWindow($entity->getTradeInventory());
					}
				}
			}
		}
		if ($packet instanceof LoginPacket) {
			$device = (int)$packet->clientData["DeviceOS"];
			$this->deviceOSData[strtolower($packet->username)] = $device;
		}
	}

	public function onQuit(PlayerQuitEvent $event)
	{
		$player = $event->getPlayer();
		if (isset($this->deviceOSData[strtolower($player->getName())])) unset($this->deviceOSData[strtolower($player->getName())]);
	}

	public function saveData(TradeNPC $npc)
	{
		file_put_contents($this->getDataFolder() . $npc->getNameTag() . ".dat", $npc->getSaveNBT());
	}

	public function onDisable()
	{
		foreach ($this->getServer()->getLevels() as $level) {
			foreach ($level->getEntities() as $entity) {
				if ($entity instanceof TradeNPC) {
					file_put_contents($this->getDataFolder() . $entity->getNameTag() . ".dat", $entity->getSaveNBT());
				}
			}
		}
	}
}
