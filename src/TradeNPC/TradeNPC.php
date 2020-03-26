<?php
declare(strict_types=1);

namespace TradeNPC;

use pocketmine\entity\Human;
use pocketmine\entity\NPC as PMNPC;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNBTStream;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;

class TradeNPC extends Human implements PMNPC
{

	/** @var CompoundTag|null */
	protected $shop = null;

	public function makeRecipe(Item $buyA, Item $sell): CompoundTag
	{
		return new CompoundTag("", [
			$buyA->nbtSerialize(-1, "buyA"),
			new IntTag("maxUses", 32767),
			new ByteTag("rewardExp", 0),
			$sell->nbtSerialize(-1, "sell"),
			new IntTag("uses", 0),
			new StringTag("label", "gg")
		]);
	}

	public function addTradeItem(Item $buyA, Item $sell): void
	{
		$this->shop->getListTag("Recipes")->push($this->makeRecipe($buyA, $sell));
	}

	public function getShopCompoundTag(): CompoundTag
	{
		return $this->shop;
	}

	public function saveNBT(): void
	{
		parent::saveNBT();
		Main::getInstance()->saveData($this);
	}

	public function getSaveNBT(): string
	{
		return (new LittleEndianNBTStream())->write($this->shop);
	}

	public function loadData(CompoundTag $nbt): void
	{
		$this->shop = $nbt;
	}

	public function initEntity(): void
	{
		parent::initEntity();
		if ($this->shop === null) {
			Main::getInstance()->loadData($this);
		}
	}

	public function getTradeInventory(): TradeInventory
	{
		return new TradeInventory($this);
	}

	public function attack(EntityDamageEvent $source): void
	{
		$source->setCancelled();
	}
}