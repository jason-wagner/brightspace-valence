<?php


class OrgUnitType extends Block
{
	public $Id;
	public $Code;
	public $Name;
	public $Description;
	public $SortOrder;
	public $Permissions;

	public function __construct(array $response) {
		foreach(['Id', 'Code', 'Name', 'Description', 'SortOrder'] as $key)
			$this->$key = $response[$key];

		$this->Permissions = new Permissions(['CanDelete' => $response['CanDelete'], 'CanEdit' => $response['CanEdit']]);
	}
}