<?php

namespace BrightspaceDevHelper\Valence\Structure;

class BlockArray
{
	public $data;
	public $pointer = 0;

	public function array(): array
	{
		return $this->data;
	}
}
