<?php

declare(strict_types=1);

namespace muqsit\chunkgenerator;

use Generator;
use Logger;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;

final class Loader extends PluginBase{

	private function buildChunkCoordinateGenerator(int $min_chunk_x, int $min_chunk_z, int $max_chunk_x, int $max_chunk_z) : Generator{
		for($x = $min_chunk_x; $x <= $max_chunk_x; ++$x){
			for($z = $min_chunk_z; $z <= $max_chunk_z; ++$z){
				yield [$x, $z];
			}
		}
	}

	private function generateChunks(World $world, int $min_chunk_x, int $min_chunk_z, int $max_chunk_x, int $max_chunk_z, int $buffer_size = 64) : void{
		$logger = $this->getLogger();
		$iterations = (1 + ($max_chunk_x - $min_chunk_x)) * (1 + ($max_chunk_z - $min_chunk_z));
		$iterated = 0;
		$populating = 0;
		$populated = 0;
		$generator = $this->buildChunkCoordinateGenerator($min_chunk_x, $min_chunk_z, $max_chunk_x, $max_chunk_z);
		$this->generateChunksA($world, $logger, $generator, $buffer_size, $iterations, $iterated, $populated, $populating);
	}

	private function generateChunksA(World $world, Logger $logger, Generator $coordinate_generator, int $buffer_size, int $iterations, int &$iterated, int &$populated, int &$populating) : void{
		$population_callback = function(int $x, int $z, bool $success) use($logger, &$populating, &$populated, $coordinate_generator, $buffer_size, $iterations, &$iterated, $world) : void{
			++$populated;
			if($success){
				$logger->info("Populated chunk({$x}, {$z}) (" . round(($populated / $populating) * 100, 2) . "% chunks populated)");
			}else{
				$logger->info("Failed to populate chunk({$x}, {$z}) (" . round(($populated / $populating) * 100, 2) . "% chunks populated)");
			}
			if($populated === $populating){
				$this->generateChunksA($world, $logger, $coordinate_generator, $buffer_size, $iterations, $iterated, $populated, $populating);
			}
		};

		$order = [];
		for($i = 0; $i < $buffer_size; ++$i){
			$current = $coordinate_generator->current();
			if($current === null){
				break;
			}

			$coordinate_generator->next();

			[$x, $z] = $current;
			++$iterated;
			if($world->loadChunk($x, $z) !== null){
				continue;
			}

			$logger->info("Ordering chunk({$x}, {$z}) for population (" . round(($iterated / $iterations) * 100, 2) . "% chunks traversed)");
			++$populating;
			$order[] = [$x, $z];
		}

		if(count($order) === 0){
			if($coordinate_generator->valid()){
				$this->generateChunksA($world, $logger, $coordinate_generator, $buffer_size, $iterations, $iterated, $populated, $populating);
			}
			return;
		}

		foreach($order as [$x, $z]){
			$world->orderChunkPopulation($x, $z, null)->onCompletion(
				function(Chunk $_) use($population_callback, $x, $z) : void{ $population_callback($x, $z, true); },
				function() use($population_callback, $x, $z) : void{ $population_callback($x, $z, false); }
			);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(!isset($args[0])){
			return false;
		}

		$world_name = array_shift($args);
		$world = $this->getServer()->getWorldManager()->getWorldByName($world_name);
		if($world === null){
			$sender->sendMessage("No world with the folder name \"{$world_name}\" found.");
			return false;
		}

		$int_args = [];
		foreach($args as $arg){
			if(is_numeric($arg)){
				$int_args[] = (int) $arg;
			}else{
				return false;
			}
		}

		if(count($int_args) < 4){
			return false;
		}

		[$minx, $minz, $maxx, $maxz] = $int_args;
		if($minx > $maxx){
			$sender->sendMessage("minchunkx must be > maxchunkx ({$minx} > {$maxx})");
			return false;
		}

		if($minz > $maxz){
			$sender->sendMessage("minchunkz must be > maxchunkz ({$minz} > {$maxz})");
			return false;
		}

		$this->generateChunks($world, $minx, $minz, $maxx, $maxz);
		return true;
	}
}