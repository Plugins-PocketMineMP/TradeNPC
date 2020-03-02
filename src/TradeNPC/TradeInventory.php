<?php
declare(strict_types=1);

namespace TradeNPC;

use pocketmine\inventory\BaseInventory;
use pocketmine\inventory\ContainerInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\network\mcpe\protocol\types\WindowTypes;
use pocketmine\network\mcpe\protocol\UpdateTradePacket;
use pocketmine\Player;

class TradeInventory extends ContainerInventory
{

	protected $npc;

	public function __construct(TradeNPC $holder)
	{
		parent::__construct(new Vector3(), [], 27);
		$this->npc = $holder;
	}

	public function getName(): string
	{
		return "TradeInventory";
	}

	public function getDefaultSize(): int
	{
		return 3; // TODO: Enable the slot 2
	}

	public function getNetworkType(): int
	{
		return WindowTypes::TRADING;
	}

	public function onOpen(Player $who): void
	{
		BaseInventory::onOpen($who);

		$pk = new UpdateTradePacket();
		$pk->displayName = $this->npc->getName();
		$pk->windowId = $id = $who->getWindowId($this);
		$pk->isWilling = false;
		$pk->isV2Trading = false;
		$pk->tradeTier = 1;
		$pk->playerEid = $who->getId();
		$pk->traderEid = $this->npc->getId();
		$pk->offers = (new NetworkLittleEndianNBTStream())->write($this->npc->getShopCompoundTag());
		$who->sendDataPacket($pk);
		TradeDataPool::$windowIdData[$who->getName()] = $id;
		TradeDataPool::$interactNPCData[$who->getName()] = $this->npc;
	}

	public function onClose(Player $who): void
	{
		BaseInventory::onClose($who);
		unset(TradeDataPool::$windowIdData[$who->getName()]);
		unset(TradeDataPool::$interactNPCData[$who->getName()]);
	}
}