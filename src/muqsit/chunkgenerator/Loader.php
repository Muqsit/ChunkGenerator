<?php

declare(strict_types=1);

namespace muqsit\chunkgenerator;

use Generator;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\CancellableClosureTask;
use pocketmine\world\World;

final class Loader extends PluginBase{

	private const MAX_LOADED_CHUNKS_ANY_ANY_GIVEN_INSTANCE = 40401;

	private function generateChunks(World $world, int $minChunkX, int $minChunkZ, int $maxChunkX, int $maxChunkZ) : void{
		$loaded_chunks = 0;
		$iterated = 0;
		$population_queue = [];
		$logger = $this->getLogger();

		/** @var Generator $generator */
		$generator = (static function() use($world, $minChunkX, $minChunkZ, $maxChunkX, $maxChunkZ, &$loaded_chunks, &$iterated, &$population_queue, $logger) : Generator{
			for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
				for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
					++$iterated;

					$chunk = $world->getChunk($chunkX, $chunkZ);
					if($chunk !== null){
						if($chunk->isPopulated()){
							yield true;
							continue;
						}
						$population_queue[World::chunkHash($chunkX, $chunkZ)] = $chunk;
					}

					$generator = new ChunkGenerator($chunkX, $chunkZ, static function(ChunkGenerator $generator) use(&$population_queue) : void{
						$population_queue[World::chunkHash($generator->getX(), $generator->getZ())] = $generator;
					}, static function(ChunkGenerator $generator) use($world, &$loaded_chunks, $logger) : void{
						$world->unregisterChunkListener($generator, $generator->getX(), $generator->getZ());
						$world->unregisterChunkLoader($generator, $generator->getX(), $generator->getZ());
						--$loaded_chunks;
						$logger->info($loaded_chunks . " pending chunks to be generated");
					});

					$world->registerChunkListener($generator, $chunkX, $chunkZ);
					$world->registerChunkLoader($generator, $chunkX, $chunkZ);
					++$loaded_chunks;
					yield true;
				}
			}
		})();

		$iterations = (1 + ($maxChunkX - $minChunkX)) * (1 + ($maxChunkZ - $minChunkZ));
		$this->getScheduler()->scheduleRepeatingTask(new CancellableClosureTask(static function() use(&$loaded_chunks, &$iterated, &$population_queue, $world, $iterations, $generator, $logger) : bool{
			foreach($population_queue as $index => $gen){
				if($world->populateChunk($gen->getX(), $gen->getZ())){
					unset($population_queue[$index]);
					if(count($population_queue) === 0 && !$generator->valid()){
						return false;
					}
				}
			}

			while($iterated !== $iterations && $loaded_chunks < self::MAX_LOADED_CHUNKS_ANY_ANY_GIVEN_INSTANCE){
				$generator->send(true);
				if(!$generator->valid() && count($population_queue) === 0){
					return false;
				}

				$logger->info("Iterated over " . $iterated . " / " . $iterations . " chunks (" . sprintf("%0.2f", ($iterated / $iterations) * 100) . "%)");
			}
			return true;
		}), 1);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(isset($args[0])){
			$world_name = array_shift($args);
			$world = $this->getServer()->getWorldManager()->getWorldByName($world_name);
			if($world !== null){
				$int_args = [];
				foreach($args as $arg){
					if(is_numeric($arg)){
						$int_args[] = (int) $arg;
					}
				}

				if(count($int_args) === 4){
					[$minx, $minz, $maxx, $maxz] = $int_args;
					if($minx <= $maxx){
						if($minz <= $maxz){
							$this->generateChunks($world, $minx, $minz, $maxx, $maxz);
							return true;
						}
						$sender->sendMessage("minchunkz must be > maxchunkz (" . $minz . " > " . $maxz . ")");
					}else{
						$sender->sendMessage("minchunkx must be > maxchunkx (" . $minx . " > " . $maxx . ")");
					}
				}
			}else{
				$sender->sendMessage("No world with the folder name \"" . $world_name . "\" found.");
			}
		}
		return false;
	}
}