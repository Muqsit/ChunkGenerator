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

	private function generateChunks(World $world, int $minChunkX, int $minChunkZ, int $maxChunkX, int $maxChunkZ, int $population_queue_size) : void{
		$loaded_chunks = 0;
		$iterated = 0;
		$population_queue = [];
		$logger = $this->getLogger();

		$generator = (static function() use($world, $minChunkX, $minChunkZ, $maxChunkX, $maxChunkZ, &$loaded_chunks, &$iterated, &$population_queue) : Generator{
			for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
				for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
					++$iterated;

					$chunk = $world->isChunkLoaded($chunkX, $chunkZ) ? $world->getChunk($chunkX, $chunkZ) : null;
					if($chunk !== null){
						if($chunk->isPopulated()){
							yield true;
							continue;
						}
						$population_queue[World::chunkHash($chunkX, $chunkZ)] = $chunk;
					}

					$generator = new ChunkGenerator($chunkX, $chunkZ, static function(ChunkGenerator $generator) use(&$population_queue) : void{
						$population_queue[World::chunkHash($generator->getX(), $generator->getZ())] = $generator;
					}, static function(ChunkGenerator $generator) use($world, &$loaded_chunks) : void{
						$world->unregisterChunkListener($generator, $generator->getX(), $generator->getZ());
						$world->unregisterChunkLoader($generator, $generator->getX(), $generator->getZ());
						--$loaded_chunks;
					});

					$world->registerChunkListener($generator, $chunkX, $chunkZ);
					$world->registerChunkLoader($generator, $chunkX, $chunkZ);
					++$loaded_chunks;
					yield true;
				}
			}
		})();

		$iterations = (1 + ($maxChunkX - $minChunkX)) * (1 + ($maxChunkZ - $minChunkZ));
		$this->getScheduler()->scheduleRepeatingTask(new CancellableClosureTask(static function() use(&$loaded_chunks, &$iterated, &$population_queue, $world, $iterations, $generator, $logger, $population_queue_size) : bool{
			foreach($population_queue as $index => $gen){
				if($world->populateChunk($gen->getX(), $gen->getZ(), true)){
					unset($population_queue[$index]);
					if(count($population_queue) === 0 && !$generator->valid()){
						return false;
					}
				}
			}

			while($iterated !== $iterations && $loaded_chunks < $population_queue_size){
				$generator->send(true);
				if(!$generator->valid() && count($population_queue) === 0){
					return false;
				}

				$logger->info("Completed {$iterated} / {$iterations} chunks (" . sprintf("%0.2f", ($iterated / $iterations) * 100) . "%, {$loaded_chunks} chunks are currently being populated)");
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
					}else{
						return false;
					}
				}

				if(count($int_args) >= 4){
					[$minx, $minz, $maxx, $maxz] = $int_args;
					$population_queue_size = $int_args[4] ?? (int) $this->getServer()->getConfigGroup()->getProperty("chunk-generation.population-queue-size", 2);
					if($minx <= $maxx){
						if($minz <= $maxz){
							$this->generateChunks($world, $minx, $minz, $maxx, $maxz, $population_queue_size);
							return true;
						}
						$sender->sendMessage("minchunkz must be > maxchunkz ({$minz} > {$maxz})");
					}else{
						$sender->sendMessage("minchunkx must be > maxchunkx ({$minx} > {$maxx})");
					}
				}
			}else{
				$sender->sendMessage("No world with the folder name \"{$world_name}\" found.");
			}
		}
		return false;
	}
}