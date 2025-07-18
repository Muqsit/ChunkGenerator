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
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\Channel;
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

		$k = 3;
		$min_x_mod = ($x1 % $k + $k) % $k;
		$min_z_mod = ($z1 % $k + $k) % $k;
		for($offset_x = 0; $offset_x < $k; $offset_x++){
			for($offset_z = 0; $offset_z < $k; $offset_z++){
				$x_start = $x1 + (($offset_x - $min_x_mod + $k) % $k);
				$z_start = $z1 + (($offset_z - $min_z_mod + $k) % $k);
				for($z = $z_start; $z <= $z2; $z += $k){
					for($x = $x_start; $x <= $x2; $x += $k){
						yield [$x, $z];
					}
				}
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
		$loader = new class implements ChunkLoader{};

		$channel = new Channel();
		$schedule = static fn(int $x, int $z) => $world->orderChunkPopulation($x, $z, $loader)->onCompletion(
			fn($c) => $channel->sendWithoutWait([$x, $z, true]),
			fn() => $channel->sendWithoutWait([$x, $z, false]),
		);

		$i = 0;
		while($i < $n_batch && $points->valid()){
			[$x, $z] = $points->current();
			$schedule($x, $z);
			$points->next();
			$i++;
		}

		$manager = $this->getServer()->getWorldManager();
		$id = $world->getId();
		$completed = 0;
		$failed = [];
		$retry = true;
		while($i > 0){
			[$x, $z, $success] = yield from $channel->receive();
			$i--;

			$world->unregisterChunkLoader($loader, $x, $z);
			if($success){
				$completed++;
			}else{
				$failed[] = [$x, $z];
			}

			if($points->valid() && $manager->getWorld($id) !== null /* equivalent of $world->isLoaded() but we cant rely on that method here */){
				[$x, $z] = $points->current();
				$schedule($x, $z);
				$points->next();
				$i++;
			}

			yield [$completed, count($failed), $count] => Traverser::VALUE;
			if($i === 0 && $retry && count($failed) > 0){ // retry failed requests once before we quit
				$retry = false;
				$points = new ArrayIterator($failed);
				$failed = [];
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
		$last_pct = -0.01;
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
				if($message === ""){
					$message = null;
					continue;
				}
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
				$pct = ($completed / $count) * 100;
				if($pct - $last_pct >= 0.01){
					$last_pct = $pct;
					$message = "{$world_name}: {$completed} / {$count} succeeded [" . sprintf("%.2f", ($completed / $count) * 100) . "%], {$failed} failed";
				}else{
					$message = "";
				}
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