<?php
declare(strict_types=1);
namespace Minion;

use onebone\economyapi\EconomyAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Skin;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\item\ItemIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class Loader extends PluginBase implements Listener{

	protected $prefix;

	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		EntityFactory::register(Minion::class, ["Minion"]);
		$this->saveResource("config.yml");

		$this->prefix = $this->getConfig()->getNested("prefix", "§b§l[ 미니언 ] §r§7");
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if($sender instanceof Player){
			if($command->getName() === "minion"){
				if(!$sender->hasPermission("minion.command.create")){
					return false;
				}
				$skin = $sender->getSkin();

				$nbt = EntityFactory::createBaseNBT($sender->getPosition()->asVector3(), null, $sender->getLocation()->getYaw(), $sender->getLocation()->getPitch());

				$nbt->setTag("Skin", CompoundTag::create()
						->setString("Name", $skin->getSkinId())
						->setByteArray("Data", $skin->getSkinData())
				);
				$nbt->setString("owner", $sender->getName());
				$nbt->setInt("type", (isset($args[0]) ? (int)$args[0] : 1));

				$minion = EntityFactory::create(Minion::class, $sender->getWorld(), $nbt);

				$minion->spawnToAll();
			}elseif($command->getName() === "minionshop"){
				if(!$sender->hasPermission("minion.command")){
					return false;
				}
				$this->sendShopUI($sender);
			}
		}
		return true;
	}

	public function getMinionItem(Player $player, int $type, Skin $skin) : Item{
		$item = VanillaItems::PAPER();

		$item->getNamedTag()->setTag("Minion", CompoundTag::create()
			->setInt("type", $type)
			->setTag("Skin", CompoundTag::create()
				->setString("Name", $skin->getSkinId())
				->setByteArray("Data", $skin->getSkinData())
			)
			->setString("owner", $player->getName())
		);

		$item->setCustomName(($type === Minion::TYPE_WHEAT ? "농부" : "광부") . " 미니언 생성");

		$item->setCount(1);

		return $item;
	}

	public function handleInteract(PlayerInteractEvent $event){
		$player = $event->getPlayer();
		$item = $event->getItem();

		if($item->getNamedTag()->hasTag("Minion", CompoundTag::class)){
			/** @var CompoundTag $nbt */
			$nbt = $item->getNamedTag()->getTag("Minion");

			$type = $nbt->getInt("type");

			$owner = $nbt->getString("owner");

			$skin = $nbt->getCompoundTag("Skin");

			$entityNBT = EntityFactory::createBaseNBT($event->getBlock()->getPos()->add(0, 1), null, $player->getLocation()->getYaw(), $player->getLocation()->getPitch());

			$entityNBT->setInt("type", $type);
			$entityNBT->setString("owner", $owner);
			$entityNBT->setTag("Skin", $skin);

			if($nbt->hasTag("MinionInventory", ListTag::class)){
				$entityNBT->setTag("MinionInventory", $nbt->getListTag("MinionInventory"));
			}

			$entity = EntityFactory::create(Minion::class, $player->getWorld(), $entityNBT);

			$entity->spawnToAll();

			$player->sendMessage($this->prefix . "Minion created successfully.");

			$item->setCount($item->getCount() - 1);
			$player->getInventory()->setItemInHand($item);
		}
	}

	public function sendShopUI(Player $player){
		$encode = [
			"type" => "form",
			"title" => "Minion Shop",
			"content" => "Please click!",
			"buttons" => [
				[
					"text" => "Exit"
				],
				[
					"text" => "Buy Farmer Minions" . TextFormat::EOL . "price: " . $this->getConfig()->getNested("farmer-price")
				],
				[
					"text" => "Buy miner minions" . TextFormat::EOL . "price: " . $this->getConfig()->getNested("miner-price")
				]
			]
		];

		$player->getNetworkSession()->sendDataPacket(ModalFormRequestPacket::create(88881, json_encode($encode)));
	}

	public function handleReceive(DataPacketReceiveEvent $event){
		$player = $event->getOrigin()->getPlayer();
		$packet = $event->getPacket();

		if($packet instanceof ModalFormResponsePacket){
			if($packet->formId === 88881){
				$data = json_decode($packet->formData, true);

				switch($data){
					case 0:
						break;
					case 1:
						$price = (int)$this->getConfig()->getNested("farmer-price");

						if(EconomyAPI::getInstance()->reduceMoney($player, $price) !== EconomyAPI::RET_SUCCESS){
							$player->sendMessage($this->prefix . "You have ran out of money. " . $price);
							break;
						}

						$player->sendMessage($this->prefix . "Purchased.");
						$player->getInventory()->addItem($this->getMinionItem($player, Minion::TYPE_WHEAT, $player->getSkin()));
						break;
					case 2:
						$price = (int)$this->getConfig()->getNested("miner-price");

						if(EconomyAPI::getInstance()->reduceMoney($player, $price) !== EconomyAPI::RET_SUCCESS){
							$player->sendMessage($this->prefix ."You don't have enough money. It costs " . $price);
							break;
						}

						$player->sendMessage($this->prefix . "Purchased.");
						$player->getInventory()->addItem($this->getMinionItem($player, Minion::TYPE_MINE, $player->getSkin()));
						break;
				}
			}
		}
	}

	public function handleTransaction(InventoryTransactionEvent $event){
		$player = $event->getTransaction()->getSource();
		foreach($event->getTransaction()->getActions() as $action){
			if($action instanceof SlotChangeAction){
				$inv = $action->getInventory();
				if($inv instanceof MinionInventory){
					$item = $action->getSourceItem();

					if($item->getId() === ItemIds::STAINED_GLASS_PANE and $item->getMeta() === 14){
						if($item->getNamedTag()->hasTag("restore", StringTag::class)){
							$event->setCancelled();

							$minion = $inv->getMinion();

							$type = $minion->getType();

							$minionItem = $this->getMinionItem($player, $type, $player->getSkin());

							$inventoryTag = new ListTag();

							foreach($inv->getContents(true) as $slot => $content){
								if(!$item->getNamedTag()->hasTag("restore", StringTag::class)){
									$inventoryTag->push($content->nbtSerialize($slot));
								}
							}

							$minionItem->getNamedTag()->setTag("MinionInventory", $inventoryTag);

							$player->getInventory()->addItem($minionItem);

							$minion->close();

							$player->sendMessage($this->prefix . "I recovered the minions.");

							$inv->onClose($player);
							break;
						}
					}
				}
			}
		}
	}
}
