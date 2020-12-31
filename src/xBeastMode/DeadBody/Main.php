<?php
declare(strict_types=1);
namespace xBeastMode\DeadBody;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Human;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\Listener;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBinaryStream;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
class Main extends PluginBase implements Listener{
        public function onEnable(){
                $this->getServer()->getPluginManager()->registerEvents($this, $this);
        }

        /** @var bool[] */
        protected $remove_queue = [];

        /**
         * @param EntitySpawnEvent $event
         */
        public function onEntitySpawn(EntitySpawnEvent $event){
                $target = $event->getEntity();

                if(!$target instanceof Human || !$target->namedtag->hasTag("__corpse__")) return;

                $target->getDataPropertyManager()->setBlockPos(Human::DATA_PLAYER_BED_POSITION, $target->floor());
                $target->setPlayerFlag(Human::DATA_PLAYER_FLAG_SLEEP, true);

                $target->sendData($target->level->getPlayers());
        }

        /**
         * @param EntityDamageEvent $event
         */
        public function onEntityDamage(EntityDamageEvent $event){
                $target = $event->getEntity();
                if($event instanceof EntityDamageByEntityEvent){
                        if(!$target->namedtag->hasTag("__corpse__")) return;

                        $damager = $event->getDamager();
                        if($damager instanceof Player && isset($this->remove_queue[spl_object_hash($damager)])){
                                $damager->sendMessage("Removed target.");
                                $target->flagForDespawn();

                                unset($this->remove_queue[spl_object_hash($damager)]);
                        }

                        $event->setCancelled();
                }
        }

        /**
         * @param string $skin_data
         *
         * @return string
         */
        protected function skinToGreyScale(string $skin_data): string{
                $stream = new NetworkBinaryStream();
                $stream->buffer = $skin_data;

                $new_stream = new NetworkBinaryStream();
                $loop = 0;

                switch(strlen($skin_data)){
                        case (64 * 32 * 4):
                                $loop = 64 * 32;
                                break;
                        case (64 * 64 * 4):
                                $loop = 64 * 64;
                                break;
                        case (128 * 128 * 4):
                                $loop = 128 * 128;
                                break;
                }

                for($i = 0; $i < $loop; $i++){
                        $r = $stream->getByte() & 0xFF;
                        $g = $stream->getByte() & 0xFF;
                        $b = $stream->getByte() & 0xFF;
                        $a = $stream->getByte() & 0xFF;

                        $r = $g = $b = (int) round(($r + $g + $b) / 3);

                        $new_stream->putByte($r);
                        $new_stream->putByte($g);
                        $new_stream->putByte($b);
                        $new_stream->putByte($a);
                }

                return $new_stream->buffer;
        }

        /**
         * @param CommandSender $sender
         * @param Command       $command
         * @param string        $label
         * @param array         $args
         *
         * @return bool
         */
        public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{
                if(!$sender instanceof Player) return false;
                switch(strtolower($command->getName())){
                        case "addbody":
                                $skin_data = clone $sender->namedtag->getTag("Skin");

                                /** @var CompoundTag $skin_data */

                                $skin_byte_data = $skin_data->getByteArray("Data");
                                $cape_data = $skin_data->getByteArray("CapeData");
                                $geometry_name = $skin_data->getString("GeometryName");
                                $geometry_data = $skin_data->getByteArray("GeometryData");

                                $base_nbt = Human::createBaseNBT($sender);

                                $base_nbt->setTag($skin_data);
                                $base_nbt->setByte("__corpse__", 1);

                                $human = Human::createEntity("Human", $sender->level, $base_nbt);

                                if($human instanceof Human){
                                        $vector = $sender->asVector3()->floor();
                                        $sender->setComponents($vector->x, $vector->y, $vector->z);

                                        $human->getDataPropertyManager()->setBlockPos(Human::DATA_PLAYER_BED_POSITION, $sender->floor());
                                        $human->setPlayerFlag(Human::DATA_PLAYER_FLAG_SLEEP, true);

                                        $skin = new Skin(
                                            "Steve" . microtime(false),
                                            $this->skinToGreyScale($skin_byte_data),
                                            $cape_data,
                                            $geometry_name,
                                            $geometry_data
                                        );

                                        $human->setSkin($skin);
                                        $human->sendSkin($human->level->getPlayers());
                                        $human->sendData($human->level->getPlayers());

                                        $human->spawnToAll();

                                        $sender->sendMessage("Corpse created.");
                                        return true;
                                }

                                $sender->sendMessage("Failed to create human corpse.");
                                return false;
                        case "removebody":
                                $sender->sendMessage("Tap entity to remove it.");
                                $this->remove_queue[spl_object_hash($sender)] = true;
                                return true;
                }
                return true;
        }
}
