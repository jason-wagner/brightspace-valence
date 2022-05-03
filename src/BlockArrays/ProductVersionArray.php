<?php

namespace BrightspaceDevHelper\Valence\BlockArray;

use BrightspaceDevHelper\Valence\Block\ProductVersions;
use BrightspaceDevHelper\Valence\Structure\BlockArray;

class ProductVersionArray extends BlockArray
{
	public $blockClass = ProductVersions::class;

	public function next(): ?ProductVersions
	{
		return parent::next();
	}
}
