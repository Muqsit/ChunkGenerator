<?php

declare(strict_types=1);

namespace muqsit\chunkgenerator;

use ArrayIterator;
use Generator;
use InvalidArgumentException;
use Iterator;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\Traverser;
use function array_push;
use function array_shift;
use function count;
use function sprintf;

final class Loader extends PluginBase{

	/**
	 * @param int $x1
	 * @param int $x2
	 * @param int $z1
	 * @param int $z2
	 * @return Generator<array{int, int}>
	 */
	private function betweenPoints(int $x1, int $x2, int $z1, int $z2) : Generator{
		$x2 >= $x1 || throw new InvalidArgumentException("x2 ({$x2}) must be >= x1 ({$x1})");
		$z2 >= $z1 || throw new InvalidArgumentException("z2 ({$z2}) must be >= z1 ({$z1})");
		for($x = $x1; $x <= $x2; $x++){
			for($z = $z1; $z <= $z2; $z++){
				yield [$x, $z];
			}
		}
	}

	/**
	 * @param World $world
	 * @param int $x1
	 * @param int $x2
	 * @param int $z1
	 * @param int $z2
	 * @param int|null $n_batch
	 * @return Generator<array{int, int}, Traverser::VALUE|Await::RESOLVE>
	 */
	public function generateChunksBetween(World $world, int $x1, int $x2, int $z1, int $z2, ?int $n_batch = null) : Generator{
		$points = $this->betweenPoints($x1, $x2, $z1, $z2);
		$count = (1 + ($x2 - $x1)) * (1 + ($z2 - $z1));
		return $this->generateChunks($world, $points, $count, $n_batch);
	}

	/**
	 * @param World $world
	 * @param Iterator<array{int, int}> $points
	 * @param int $count
	 * @param int|null $n_batch
	 * @return Generator<array{int, int}, Traverser::VALUE|Await::RESOLVE>
	 */
	public function generateChunks(World $world, Iterator $points, int $count, ?int $n_batch = null) : Generator{
		$n_batch ??= $this->getServer()->getConfigGroup()->getPropertyInt(YmlServerProperties::CHUNK_GENERATION_POPULATION_QUEUE_SIZE, 2);
		$n_batch > 0 || throw new InvalidArgumentException("n_batch ({$n_batch}) must be > 0");
		$completed = 0;
		$failed = [];
		$loader = new class implements ChunkLoader{};
		while(true){
			while($points->valid()){
				if(!$world->isLoaded() || !$this->isEnabled() || !$this->getServer()->isRunning()){
					yield [$completed, $count - $completed, $count] => Traverser::VALUE;
					break 2;
				}

				$tasks = [];
				for($i = 0; $i < $n_batch && $points->valid(); $i++, $points->next()){
					[$x, $z] = $points->current();
					$tasks[] = Await::promise(static fn($resolve) => $world->orderChunkPopulation($x, $z, $loader)
						->onCompletion(fn(Chunk $_) => $resolve([$x, $z, true]), fn() => $resolve([$x, $z, false])));
				}
				$result = yield from Await::all($tasks);
				foreach($result as [$x, $z, $success]){
					$world->unregisterChunkLoader($loader, $x, $z);
					if($success){
						$completed++;
					}else{
						$failed[] = [$x, $z];
					}
				}
				$world->unloadChunks();
				yield [$completed, count($failed), $count] => Traverser::VALUE;
			}
			if(count($failed) > 0){
				$points = new ArrayIterator($failed);
				$failed = [];
			}else{
				break;
			}
		}
	}

	/**
	 * @param World $world
	 * @param Generator<array{int, int}, Traverser::VALUE|Await::RESOLVE> $task
	 * @param CommandSender $sender
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function generateAndReport(World $world, Generator $task, CommandSender $sender) : Generator{
		$world_name = $world->getFolderName();
		$operation = new Traverser($task);
		$message = null;
		$progress = null;
		$states = [0];
		while(($state = array_shift($states)) !== null){
			if($state === 0){ // state=initialization - check for errors
				try{
					yield from $operation->next($progress);
					array_push($states, 3, 1, 2);
				}catch(InvalidArgumentException $e){
					$message = $e->getMessage();
					$states[] = 1;
				}
			}elseif($state === 1){ // state=send message
					$message ?? throw new RuntimeException("Requested state without setting a message");
				$sender = $sender instanceof Player && $sender->isConnected() ? $sender : null;
				if($sender !== null){
					$sender->sendTip($message);
				}else{
					$this->getLogger()->info($message);
				}
				$message = null;
			}elseif($state === 2){ // state=perform chunk generation
				if(yield from $operation->next($progress)){
					array_push($states, 3, 1, 2);
				}else{
					$message = "Generation completed.";
					$states[] = 1;
				}
			}elseif($state === 3){ // state=create progress message
				[$completed, $failed, $count] = $progress;
				$message = "{$world_name}: {$completed} / {$count} succeeded [" . sprintf("%.2f", ($completed / $count) * 100) . "%], {$failed} failed";
			}else{
				throw new RuntimeException("Unexpected state ({$state}) encountered");
			}
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

		[$x1, $z1, $x2, $z2] = $int_args;
		$n_batch = $int_args[4] ?? null;
		$task = $this->generateChunksBetween($world, $x1, $x2, $z1, $z2, $n_batch);
		$sender->sendMessage("Generating...");
		Await::g2c($this->generateAndReport($world, $task, $sender));
		return true;
	}
}