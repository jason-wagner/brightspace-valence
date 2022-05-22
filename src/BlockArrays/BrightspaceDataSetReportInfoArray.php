<?php

namespace BrightspaceDevHelper\Valence\BlockArray;

use BrightspaceDevHelper\Valence\Block\BrightspaceDatasetReportInfo;
use BrightspaceDevHelper\Valence\Structure\BlockArray;

class BrightspaceDataSetReportInfoArray extends BlockArray
{
	public $blockClass = BrightspaceDatasetReportInfo::class;

	public function build(array $response): void
	{
		$this->nextPageRoute = substr($response['NextPageUrl'], strpos($response['NextPageUrl'], '/d2l/api/'));
		parent::build($response['BrightspaceDataSets']);
	}

	public function next(): ?BrightspaceDataSetReportInfo
	{
		return parent::next();
	}
}