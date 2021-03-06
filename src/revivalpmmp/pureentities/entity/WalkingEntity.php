<?php

/*  PureEntitiesX: Mob AI Plugin for PMMP
    Copyright (C) 2017 RevivalPMMP

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>. */

namespace revivalpmmp\pureentities\entity;

use pocketmine\block\Block;
use pocketmine\Player;
use revivalpmmp\pureentities\data\BlockSides;
use revivalpmmp\pureentities\entity\animal\Animal;
use revivalpmmp\pureentities\entity\monster\walking\PigZombie;
use pocketmine\block\Liquid;
use pocketmine\block\Fence;
use pocketmine\block\FenceGate;
use pocketmine\math\Math;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\entity\Creature;
use revivalpmmp\pureentities\features\IntfTameable;
use revivalpmmp\pureentities\PluginConfiguration;

abstract class WalkingEntity extends BaseEntity {

    protected function checkTarget(bool $checkSkip = true) {
        if (($checkSkip and $this->isCheckTargetAllowedBySkip()) or !$checkSkip) {
            if ($this->isKnockback()) {
                return;
            }

            $target = $this->getBaseTarget();
            if (!$target instanceof Creature or !$this->targetOption($target, $this->distanceSquared($target))) {
                $near = PHP_INT_MAX;
                foreach ($this->getLevel()->getEntities() as $creature) {
                    if ($creature === $this || !($creature instanceof Creature) || $creature instanceof Animal) {
                        continue;
                    }

                    if ($creature instanceof BaseEntity && $creature->isFriendly() == $this->isFriendly()) {
                        continue;
                    }

                    $distance = $this->distanceSquared($creature);
                    if (
                        $distance <= 100
                        && $this instanceof PigZombie && $this->isAngry()
                        && $creature instanceof PigZombie && !$creature->isAngry()
                    ) {
                        $creature->setAngry(1000);
                    }

                    if ($distance > $near or !$this->targetOption($creature, $distance)) {
                        continue;
                    }

                    $near = $distance;

                    $this->moveTime = 0;
                    $this->setBaseTarget($creature);
                }
            }

            if ($this->getBaseTarget() instanceof Creature && $this->getBaseTarget()->isAlive()) {
                return;
            }

            if ($this->moveTime <= 0 or !($this->getBaseTarget() instanceof Vector3)) {
                $x = mt_rand(20, 100);
                $z = mt_rand(20, 100);
                $this->moveTime = mt_rand(300, 1200);
                $this->setBaseTarget($this->add(mt_rand(0, 1) ? $x : -$x, 0, mt_rand(0, 1) ? $z : -$z));
            }
        }
    }

    /**
     * @param int $dx
     * @param int $dz
     *
     * @return bool
     */
    protected function checkJump($dx, $dz) {
        if ($this->motionY == $this->gravity * 2) { // swimming
            return $this->getLevel()->getBlock(new Vector3(Math::floorFloat($this->x), (int)$this->y, Math::floorFloat($this->z))) instanceof Liquid;
        } else { // dive up?
            if ($this->getLevel()->getBlock(new Vector3(Math::floorFloat($this->x), (int)($this->y + 0.8), Math::floorFloat($this->z))) instanceof Liquid) {
                $this->motionY = $this->gravity * 2; // set swimming (rather walking on water ;))
                return true;
            }
        }

        if (!$this->isOnGround() or $this->stayTime > 0) {
            return false;
        }

        if ($this->getDirection() === null) {
            return false;
        }

        $that = $this->getLevel()->getBlock(new Vector3(Math::floorFloat($this->x + $dx), (int)$this->y, Math::floorFloat($this->z + $dz)));
        $block = $that->getSide(BlockSides::getSides()[$this->getDirection()]);
        // we cannot pass through the block that is directly in front of us
        if (!$block->canPassThrough() and $this->getMaxJumpHeight() > 0) { // it's possible that an entity can't jump?! better check!
            // check if we can get through the upper of the block directly in front of the entity
            if ($block->getSide(Block::SIDE_UP)->canPassThrough() && $that->getSide(Block::SIDE_UP, 2)->canPassThrough()) {
                if ($block instanceof Fence || $block instanceof FenceGate) { // cannot pass fence or fence gate ...
                    $this->motionY = $this->gravity;
                } else if ($this->motionY <= ($this->gravity * 8)) {
                    $this->motionY = $this->gravity * 8;
                } else {
                    $this->motionY += $this->gravity * 0.25;
                }
                return true;
            }
        }
        return false;

    }

    /**
     * This function checks if upper blocks of the given block can be passed through. This
     * method brings in jumpHeight of the entitiy (normal entities can only jump one block, horses
     * e.g. jump 2 blocks ...)
     *
     * @param Block $block  the block the check starts with
     * @return bool true if upper blocks can be passed through
     */
    private function canPassThroughUpperBlocks (Block $block) : bool {
        for ($y=1; $y <= $this->getMaxJumpHeight(); $y++) {
            if (!$block->getSide(Block::SIDE_UP, ($y + 1))->canPassThrough()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param int $tickDiff
     *
     * @return null|Vector3
     */
    public function updateMove($tickDiff) {
        if (!$this->isMovement()) {
            return null;
        }

        if ($this->isKnockback()) {
            $this->move($this->motionX * $tickDiff, $this->motionY, $this->motionZ * $tickDiff);
            $this->motionY -= 0.2 * $tickDiff;
            $this->updateMovement();
            return null;
        }

        $before = $this->getBaseTarget();
        $this->checkTarget();
        if ($this->getBaseTarget() instanceof Creature or $this->getBaseTarget() instanceof Block or $before !== $this->getBaseTarget()) {
            $x = $this->getBaseTarget()->x - $this->x;
            $y = $this->getBaseTarget()->y - $this->y;
            $z = $this->getBaseTarget()->z - $this->z;

            $distance = sqrt(pow($this->x - $this->getBaseTarget()->x, 2) + pow($this->z - $this->getBaseTarget()->z, 2));

            if ($this->getBaseTarget() instanceof Block and $distance <= 1.5) {
                $this->blockOfInterestReached($this->getBaseTarget());
            } else if ($this instanceof IntfTameable and
                $this->getBaseTarget() instanceof Player
                and $this->isFriendly()
                and $this->isTamed()
                and $distance <= 6
            ) { // before moving nearer to the player, check if distance
                // this entity is tamed and the target is the owner - hold distance 4 blocks
                $this->stayTime = 50; // rest for 50 ticks before moving on ...
            }
            $diff = abs($x) + abs($z);
            if ($x ** 2 + $z ** 2 < 0.7) {
                $this->motionX = 0;
                $this->motionZ = 0;
            } else {
                $this->motionX = $this->getSpeed() * 0.15 * ($x / $diff);
                $this->motionZ = $this->getSpeed() * 0.15 * ($z / $diff);
            }
            if ($diff > 0) {
                $this->yaw = -atan2($x / $diff, $z / $diff) * 180 / M_PI;
            }
            $this->pitch = $y == 0 ? 0 : rad2deg(-atan2($y, sqrt($x ** 2 + $z ** 2)));
        }

        $dx = $this->motionX * $tickDiff;
        $dz = $this->motionZ * $tickDiff;
        $isJump = false;
        if ($this->isCollidedHorizontally or $this->isInsideOfWater()) {
            $isJump = $this->checkJump($dx, $dz);
        }
        if ($this->stayTime > 0) {
            $this->stayTime -= $tickDiff;
            $this->move(0, $this->motionY * $tickDiff, 0);
        } else {
            $be = new Vector2($this->x + $dx, $this->z + $dz);
            $this->move($dx, $this->motionY * $tickDiff, $dz);
            $af = new Vector2($this->x, $this->z);
            if (($be->x != $af->x || $be->y != $af->y) && !$isJump) {
                $this->moveTime -= 90 * $tickDiff;
            }
        }

        if (!$isJump) {
            if ($this->isOnGround()) {
                $this->motionY = 0;
            } else if ($this->motionY > -$this->gravity * 4) {
                if (!($this->getLevel()->getBlock(new Vector3(Math::floorFloat($this->x), (int)($this->y + 0.8), Math::floorFloat($this->z))) instanceof Liquid)) {
                    $this->motionY -= $this->gravity * 1;
                }
            } else {
                $this->motionY -= $this->gravity * $tickDiff;
            }
        }
        $this->updateMovement();
        return $this->getBaseTarget();
    }

    /**
     * Implement this for entities who have interest in blocks
     * @param Block $block the block that has been reached
     */
    protected function blockOfInterestReached($block) {
        // nothing important here. look e.g. Sheep.class
    }

    /**
     * Checks if this entity is following a player
     *
     * @param Creature $creature the possible player
     * @return bool
     */
    protected function isFollowingPlayer(Creature $creature): bool {
        return $this->getBaseTarget() !== null and $this->getBaseTarget() instanceof Player and $this->getBaseTarget()->getId() === $creature->getId();
    }


}
