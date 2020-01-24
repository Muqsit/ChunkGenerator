<?php

declare(strict_types=1);

namespace muqsit\chunkgenerator;

use Closure;
use pocketmine\math\Vector3;
use pocketmine\world\ChunkListener;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;

final class ChunkGenerator implements ChunkLoader, ChunkListener{

	/** @var int */
	private $chunkX;

	/** @var int */
	private $chunkZ;

	/** @var Closure */
	private $populate;

	/** @var Closure */
	private $on_populate;

	public function __construct(int $chunkX, int $chunkZ, Closure $populate, Closure $on_populate){
		$this->chunkX = $chunkX;
		$this->chunkZ = $chunkZ;
		$this->populate = $populate;
		$this->on_populate = $on_populate;
	}

	public function getX() : int{
		return $this->chunkX;
	}

	public function getZ() : int{
		return $this->chunkZ;
	}

	public function onChunkLoaded(Chunk $chunk) : void{
		if($chunk->isPopulated()){
			($this->on_populate)($this);
		}else{
			($this->populate)($this);
		}
	}

	public function onChunkPopulated(Chunk $chunk) : void{
		($this->on_populate)($this);
	}

	public function onChunkChanged(Chunk $chunk) : void{
	}

	public function onChunkUnloaded(Chunk $chunk) : void{
	}

	public function onBlockChanged(Vector3 $block) : void{
	}
}