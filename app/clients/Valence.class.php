<?php

namespace ValenceHelper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

use D2LAppContextFactory;
use D2LHostSpec;

use ValenceHelper\Block\CourseOffering;
use ValenceHelper\Block\EnrollmentData;
use ValenceHelper\Block\GroupCategoryData;
use ValenceHelper\Block\GroupData;
use ValenceHelper\Block\LegalPreferredNames;
use ValenceHelper\Block\Organization;
use ValenceHelper\Block\OrgUnitType;
use ValenceHelper\Block\ProductVersions;
use ValenceHelper\Block\Role;
use ValenceHelper\Block\SectionData;
use ValenceHelper\Block\SectionPropertyData;
use ValenceHelper\Block\UserData;
use ValenceHelper\Block\WhoAmIUser;

class Valence {
	private $httpclient, $handler;

	private $returnObjectOnCreate = false;
	private $logMode = 0;
	private $logFileHandler = null;
	private $exitOnError = true;

	public const VERSION_LP = '1.30';
	public const VERSION_LE = '1.52';

	protected $responseCode = null;
	protected $responseBody = null;
	protected $responseError = null;

	public $newUserClass = ValenceUser::class;
	public $newCourseClass = ValenceCourse::class;

	public $roleIds = [];
	public $orgtypeIds = [];

	public $rootOrgId = null;
	public $timezone = null;

	public function __construct() {
		$authContextFactory = new D2LAppContextFactory();
		$authContext = $authContextFactory->createSecurityContext($_ENV['D2L_VALENCE_APP_ID'], $_ENV['D2L_VALENCE_APP_KEY']);
		$hostSpec = new D2LHostSpec($_ENV['D2L_VALENCE_HOST'], $_ENV['D2L_VALENCE_PORT'], $_ENV['D2L_VALENCE_SCHEME']);
		$this->handler = $authContext->createUserContextFromHostSpec($hostSpec, $_ENV['D2L_VALENCE_USER_ID'], $_ENV['D2L_VALENCE_USER_KEY']);
		$this->httpclient = new Client(['base_uri' => "{$_ENV['D2L_VALENCE_SCHEME']}://{$_ENV['D2L_VALENCE_HOST']}'/"]);

		$org = $this->getOrganization();

		if($this->isValidResponseCode()) {
			$this->rootOrgId = $org->Identifier;
			$this->timezone = $org->TimeZone;
		}
	}

	public function apirequest(string $route, string $method = 'GET', ?array $data = null): ?array {
		$uri = $this->handler->createAuthenticatedUri(str_replace(' ', '%20', $route), $method);

		try {
			$response = $this->httpclient->request($method, $uri, ['json' => $data]);

			$this->responseCode = $response->getStatusCode();
			$this->responseBody = json_decode($response->getBody(), 1);
			$this->responseError = null;

			if($this->logMode == 2 || ($this->logMode == 1 && in_array($method, ['POST', 'PUT', 'DELETE'])))
				$this->logrequest($route, $method, $data);

			return $this->responseBody;
		} catch(ClientException | ServerException $exception) {
			$response = $exception->getResponse();

			$this->responseCode = $response->getStatusCode();
			$this->responseBody = null;
			$this->responseError = $response->getBody()->getContents();

			if($this->logMode == 2 || ($this->logMode == 1 && in_array($method, ['POST', 'PUT', 'DELETE'])))
				$this->logrequest($route, $method, $data);

			if($this->exitOnError) {
				fwrite(STDERR, "Error: $this->responseCode $this->responseError (exiting...)\n");
				exit(1);
			}

			return null;
		}
	}

	public function apirequestfile(string $route, string $filepath) {
		$uri = $this->handler->createAuthenticatedUri(str_replace(' ', '%20', $route), 'GET');

		try {
			$filehandler = fopen($filepath, 'w');
			$response = $this->httpclient->request('GET', $uri, ['sink' => $filehandler]);

			$this->responseCode = $response->getStatusCode();

			if($this->logMode == 2)
				$this->logrequest($route, 'GET');

			return true;
		} catch(ClientException | ServerException $exception) {
			$response = $exception->getResponse();

			$this->responseCode = $response->getStatusCode();
			$this->responseError = $response->getBody()->getContents();

			if($this->logMode == 2)
				$this->logrequest($route, 'GET');

			if($this->exitOnError) {
				fwrite(STDERR, "Error: $this->responseCode $this->responseError (exiting...)\n");
				exit(1);
			}

			return false;
		}
	}

	public function apisendfile(string $route, string $method, string $filepath, string $field, string $name) {
		$uri = $this->handler->createAuthenticatedUri(str_replace(' ', '%20', $route), $method);

		try {
			$formdata = [
				['name' => $field, 'filename' => $name, 'contents' => fopen($filepath, 'r')]
			];

			$response = $this->httpclient->request($method, $uri, ['multipart' => $formdata]);

			$this->responseCode = $response->getStatusCode();

			if($this->logMode == 2)
				$this->logrequest($route, $method, ['placeholder']);

			return true;
		} catch(ClientException | ServerException $exception) {
			$response = $exception->getResponse();

			$this->responseCode = $response->getStatusCode();
			$this->responseError = $response->getBody()->getContents();

			if($this->logMode == 2)
				$this->logrequest($route, $method, ['placeholder']);

			if($this->exitOnError) {
				fwrite(STDERR, "Error: $this->responseCode $this->responseError (exiting...)\n");
				exit(1);
			}

			return false;
		}
	}

	private function logrequest(string $route, string $method, ?array $data = null): void {
		$logEntry = date("Y-m-d H:i:s") . " $method $route " . json_encode($data ?? []) . " $this->responseCode\n";
		fwrite($this->logFileHandler, $logEntry);
	}

	private function buildarray(array $response, $class): array {
		$return = [];

		foreach($response as $item)
			$return[] = new $class($item);

		return $return;
	}

	public function setLogging(int $logMode, ?string $logFile = null): void {
		$this->logMode = $logMode;

		if($this->logFileHandler)
			fclose($this->logFileHandler);

		$this->logFileHandler = fopen($logFile ?? 'valence.log', 'a');
	}

	public function setReturnObjectOnCreate(bool $returnobject): void {
		$this->returnObjectOnCreate = $returnobject;
	}

	public function setExitOnError(bool $exitonerror): void {
		$this->exitOnError = $exitonerror;
	}

	public function responseCode(): ?int {
		return $this->responseCode;
	}

	public function responseBody(): ?array {
		return $this->responseBody;
	}

	public function responseError(): ?string {
		return $this->responseError;
	}

	public function isValidResponseCode(): bool {
		return floor($this->responseCode()/100) == 2;
	}

	public function setUserClass($userclass): void {
		$this->newUserClass = $userclass;
	}

	public function setCourseClass($courseclass): void {
		$this->newCourseClass = $courseclass;
	}

	public function setInternalIds(): void {
		foreach ($this->getRoles() as $role)
			$this->roleIds[$role->DisplayName] = $role->Identifier;

		foreach($this->getOrgUnitTypes() as $orgtype)
			$this->orgtypeIds[$orgtype->Code] = $orgtype->Id;
	}

	public function whoami(): ?WhoAmIUser {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/users/whoami");
		return $this->isValidResponseCode() ? new WhoAmIUser($response) : null;
	}

	public function getOrganization(): ?Organization {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/organization/info");
		return $this->isValidResponseCode() ? new Organization($response) : null;
	}

	public function version(string $productCode): ?ProductVersions {
		$response = $this->apirequest("/d2l/api/$productCode/versions/");

		return $this->isValidResponseCode() ? new ProductVersions($response) : null;
	}

	public function versions(): array {
		$response = $this->apirequest("/d2l/api/versions/");
		return $this->buildarray($response, ProductVersions::class);
	}

	public function getRole($roleId): ?Role {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/roles/$roleId");
		return $this->isValidResponseCode() ? new Role($response) : null;
	}

	public function getRoles(): array {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/{$this->rootOrgId}/roles/");
		return $this->buildarray($response, Role::class);
	}

	public function getOrgUnitType($orgUnitTypeId): ?OrgUnitType {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/outypes/$orgUnitTypeId");
		return $this->isValidResponseCode() ? new OrgUnitType($response) : null;
	}

	public function getOrgUnitTypes(): array {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/outypes/");
		return $this->buildarray($response, OrgUnitType::class);
	}

	public function user(int $userid): ValenceUser {
		return new $this->newUserClass($this, $userid);
	}

	public function course(int $orgid): ValenceCourse {
		return new $this->newCourseClass($this, $orgid);
	}

	public function getUserIdFromUsername(string $username): ?int {
		try {
			$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/users/?username=$username");
			return $response['UserId'] ?? null;
		} catch(Exception $e) {
			return null;
		}
	}

	public function getUserIdFromOrgDefinedId(string $orgDefinedId): ?int {
		try {
			$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/users/?orgDefinedId=$orgDefinedId");
			return $response['UserId'] ?? null;
		} catch(Exception $e) {
			return null;
		}
	}

	public function getOrgUnitIdFromCode(string $orgUnitCode, int $orgUnitType): ?int {
		try {
			$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/orgstructure/?orgUnitType=$orgUnitType&exactOrgUnitCode=$orgUnitCode");
			return $response['Items'][0]['Identifier'] ?? null;
		} catch(Exception $e) {
			return null;
		}
	}

	public function getOrgUnitIdFromOfferingCode(string $offeringCode): ?int {
		if(!count($this->orgtypeIds))
			$this->setInternalIds();

		return $this->getOrgUnitIdFromCode($offeringCode, $this->orgtypeIds['Course Offering']);
	}

	public function getOrgUnitIdFromSemesterCode(string $semesterCode): ?int {
		if(!count($this->orgtypeIds))
			$this->setInternalIds();

		return $this->getOrgUnitIdFromCode($semesterCode, $this->orgtypeIds['Semester']);
	}

	public function getOrgUnitIdFromTemplateCode(string $templateCode): ?int {
		if(!count($this->orgtypeIds))
			$this->setInternalIds();

		return $this->getOrgUnitIdFromCode($templateCode, $this->orgtypeIds['Course Template']);
	}

	public function getOrgUnitIdFromDepartmentCode(string $departmentCode): ?int {
		if(!count($this->orgtypeIds))
			$this->setInternalIds();

		return $this->getOrgUnitIdFromCode($departmentCode, $this->orgtypeIds['Department']);
	}

	public function enrollUser(int $OrgUnitId, int $UserId, int $RoleId): ?EnrollmentData {
		$data = compact('OrgUnitId', 'UserId', 'RoleId');
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/enrollments/", "POST", $data);
		return $response ? new EnrollmentData($response) : null;
	}

	public function unenrollUser(int $userId, int $orgUnitId): void {
		$this->apirequest("/d2l/api/lp/".self::VERSION_LP."/enrollments/users/$userId/orgUnits/$orgUnitId", "DELETE");
	}

	public function getEnrollment(int $orgUnitId, int $userId): ?EnrollmentData {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/enrollments/orgUnits/$orgUnitId/users/$userId");
		return $response ? new EnrollmentData($response) : null;
	}

	public function enrollStudent(int $OrgUnitId, int $UserId): ?EnrollmentData {
		if(!count($this->roleIds))
			$this->setInternalIds();

		return $this->enrollUser($OrgUnitId, $UserId, $this->roleIds['Student']);
	}

	public function enrollInstructor(int $OrgUnitId, int $UserId): ?EnrollmentData {
		if(!count($this->roleIds))
			$this->setInternalIds();

		return $this->enrollUser($OrgUnitId, $UserId, $this->roleIds['Instructor']);
	}

	public function getCourseOffering(int $orgUnitId): ?CourseOffering {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/courses/$orgUnitId");
		return $response ? new CourseOffering($response) : null;
	}

	public function createCourseOffering(string $Name, string $Code, string $Path, int $CourseTemplateId, int $SemesterId, ?string $StartDate, ?string $EndDate, ?int $LocaleId, bool $ForceLocale, bool $ShowAddressBook, ?string $DescriptionText, bool $CanSelfRegister) {
		$data = compact('Name', 'Code', 'Path', 'CourseTemplateId', 'SemesterId', 'StartDate', 'EndDate', 'LocaleId', 'ForceLocale', 'ShowAddressBook', 'CanSelfRegister');
		$data['Description'] = ['Type' => 'Text', 'Content' => $DescriptionText];
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/courses/", "POST", $data);
		return $this->returnObjectOnCreate ? $this->course($response['Identifier']) : new CourseOffering($response);
	}

	public function updateCourseOffering(int $orgUnitId, string $Name, string $Code, ?string $StartDate, ?string $EndDate, bool $IsActive, string $DescriptionText): ?CourseOffering {
		$data = compact('Name', 'Code', 'StartDate', 'EndDate', 'IsActive');
		$data['Description'] = ['Type' => 'Text', 'Content' => $DescriptionText];
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/courses/$orgUnitId", "PUT", $data);
		return $response ? new CourseOffering($response) : null;
	}

	public function deleteCourseOffering(int $orgUnitId): void {
		$this->apirequest("/d2l/api/lp/".self::VERSION_LP."/courses/$orgUnitId", "DELETE");
	}

	public function getCourseImage(int $orgUnitId, string $filepath): bool {
		return $this->apirequestfile("/d2l/api/lp/".self::VERSION_LP."/courses/$orgUnitId/image", $filepath);
	}

	public function uploadCourseImage(int $orgUnitId, string $filepath, string $name): bool {
		return $this->apisendfile("/d2l/api/lp/".self::VERSION_LP."/courses/$orgUnitId/image", "PUT", $filepath, "Image", $name);
	}

	public function getCourseSections(int $orgUnitId): array {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/sections/");
		return $this->buildarray($response, SectionData::class);
	}

	public function getCourseSection(int $orgUnitId, int $sectionId): ?SectionData {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/sections/$sectionId");
		return $response ? new SectionData($response) : null;
	}

	public function createCourseSection(int $orgUnitId, string $Name, string $Code, string $DescriptionText): ?SectionData {
		$data = compact('Name', 'Code');
		$data['Description'] = ['Type' => 'Text', 'Content' => $DescriptionText];
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId)/sections/", "POST", $data);
		return $response ? new SectionData($response) : null;
	}

	public function updateCourseSection(int $orgUnitId, int $sectionId, string $Name, string $Code, string $DescriptionText): ?SectionData {
		$data = compact('Name', 'Code');
		$data['Description'] = ['Type' => 'Text', 'Content' => $DescriptionText];
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/sections/$sectionId", "PUT", $data);
		return $response ? new SectionData($response) : null;
	}

	public function initializeCourseSections(int $orgUnitId, int $EnrollmentStyle, int $EnrollmentQuantity, bool $AuthEnroll, bool $RandomizeEnrollments): ?SectionData {
		$data = compact('EnrollmentStyle', 'EnrollmentQuantity', 'AuthEnroll', 'RandomizeEnrollments');
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/sections/", "PUT", $data);
		return $response ? new SectionData($response) : null;
	}

	public function deleteCourseSection(int $orgUnitId, int $sectionId): void {
		$this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/sections/$sectionId", "DELETE");
	}

	public function enrollUserInCourseSection(int $orgUnitId, int $sectionId, int $UserId): array {
		$data = compact('UserId');
		return $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/sections/$sectionId/enrollments/", "POST", $data);
	}

	public function getCourseSectionSettings(int $orgUnitId): ?SectionPropertyData {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/sections/settings");
		return $response ? new SectionPropertyData($response) : null;
	}

	public function updateCourseSectionSettings(int $orgUnitId, int $EnrollmentStyle, int $EnrollmentQuantity, int $AuthEnroll, int $RandomizeEnrollments): ?SectionPropertyData {
		$data = compact('EnrollmentStyle', 'EnrollmentQuantity', 'AuthEnroll', 'RandomizeEnrollments');
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/sections/settings", "PUT", $data);
		return $response ? new SectionPropertyData($response) : null;
	}

	public function getCourseGroupCategories(int $orgUnitId): array {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/");
		return $this->buildarray($response, GroupCategoryData::class);
	}

	public function getCourseGroupCategory(int $orgUnitId, int $groupCategoryId): ?GroupCategoryData {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId");
		return $response ? new GroupCategoryData($response) : null;
	}

	public function createCourseGroupCategory(int $orgUnitId, string $Name, string $DescriptionText, int $EnrollmentStyle, ?int $EnrollmentQuantity, bool $AutoEnroll, bool $RandomizeEnrollments, ?int $NumberOfGroups, ?int $MaxUsersPerGroup, bool $AllocateAfterExpiry, ?string $SelfEnrollmentExpiryDate, ?string $GroupPrefix, ?int $RestrictedByOrgUnitId): ?GroupCategoryData {
		$data = compact('Name', 'EnrollmentStyle', 'EnrollmentQuantity', 'AutoEnroll', 'RandomizeEnrollments', 'NumberOfGroups', 'MaxUsersPerGroup', 'AllocateAfterExpiry', 'SelfEnrollmentExpiryDate', 'GroupPrefix', 'RestrictedByOrgUnitId');
		$data['Description'] = ['Type' => 'Text', 'Content' => $DescriptionText];
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/", "POST", $data);
		return $response ? new GroupCategoryData($response) : null;
	}

	public function deleteCourseGroupCategory(int $orgUnitId, int $groupCategoryId): void {
		$this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId", "DELETE");
	}

	public function updateCourseGroupCategory(int $orgUnitId, int $groupCategoryId, string $Name, string $DescriptionText, int $EnrollmentStyle, ?int $EnrollmentQuantity, bool $AutoEnroll, bool $RandomizeEnrollments, ?int $NumberOfGroups, ?int $MaxUsersPerGroup, bool $AllocateAfterExpiry, ?string $SelfEnrollmentExpiryDate, ?string $GroupPrefix, ?int $RestrictedByOrgUnitId): ?GroupCategoryData {
		$data = compact('Name', 'EnrollmentStyle', 'EnrollmentQuantity', 'AutoEnroll', 'RandomizeEnrollments', 'NumberOfGroups', 'MaxUsersPerGroup', 'AllocateAfterExpiry', 'SelfEnrollmentExpiryDate', 'GroupPrefix', 'RestrictedByOrgUnitId');
		$data['Description'] = ['Type' => 'Text', 'Content' => $DescriptionText];
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId", "PUT", $data);
		return $response ? new GroupCategoryData($response) : null;
	}

	public function getCourseGroups(int $orgUnitId, int $groupCategoryId): array {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId/groups/");
		return $this->buildarray($response, GroupData::class);
	}

	public function getCourseGroup(int $orgUnitId, int $groupCategoryId, int $groupId): ?GroupData {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId/groups/$groupId");
		return $response ? new GroupData($response) : null;
	}

	public function createCourseGroup(int $orgUnitId, int $groupCategoryId, string $Name, string $Code, string $DescriptionText): ?GroupData {
		$data = compact('Name', 'Code');
		$data['Description'] = ['Type' => 'Text', 'Content' => $DescriptionText];
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId/groups/", "POST", $data);
		return $response ? new GroupData($response) : null;
	}

	public function updateCourseGroup(int $orgUnitId, int $groupCategoryId, int $groupId, string $Name, string $Code, string $DescriptionText): ?GroupData {
		$data = compact('Name', 'Code');
		$data['Description'] = ['Type' => 'Text', 'Content' => $DescriptionText];
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId/groups/$groupId", "PUT", $data);
		return $response ? new GroupData($response) : null;
	}

	public function enrollUserInGroup(int $orgUnitId, int $groupCategoryId, int $groupId, int $UserId): array {
		$data = compact('UserId');
		return $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId/groups/$groupId/enrollments/", "POST", $data);
	}

	public function unenrollUserFromGroup(int $orgUnitId, int $groupCategoryId, int $groupId, int $userId): void {
		$this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId/groups/$groupId/enrollments/$userId", "DELETE");
	}

	public function deleteCourseGroup(int $orgUnitId, int $groupCategoryId, int $groupId): void {
		$this->apirequest("/d2l/api/lp/".self::VERSION_LP."/$orgUnitId/groupcategories/$groupCategoryId/groups/$groupId", "DELETE");
	}

	public function getUser(int $userId): ?UserData {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/users/$userId");
		return $response ? new UserData($response) : null;
	}

	public function getUserNames(int $userId): ?LegalPreferredNames {
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/users/$userId/names");
		return $this->isValidResponseCode() ? new LegalPreferredNames($response) : null;
	}

	public function updateUserNames(int $userId, string $LegalFirstName, string $LegalLastName, ?string $PreferredFirstName, ?string $PreferredLastName): ?LegalPreferredNames {
		$data = compact('LegalFirstName', 'LegalLastName', 'PreferredFirstName', 'PreferredLastName');
		$response = $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/users/$userId/names", "PUT", $data);
		return $this->isValidResponseCode() ? new LegalPreferredNames($response) : null;
	}

	public function getUserProfile(int $userId): array {
		return $this->apirequest("/d2l/api/lp/".self::VERSION_LP."/profile/user/$userId");
	}

	public function getUserPicture(int $userId, string $filepath): bool {
		return $this->apirequestfile("/d2l/api/lp/".self::VERSION_LP."/profile/user/$userId/image", $filepath);
	}

	public function uploadUserPicture(int $userId, string $filepath): bool {
		return $this->apisendfile("/d2l/api/lp/".self::VERSION_LP."/profile/user/$userId/image", "POST", $filepath, 'profileImage', 'profileImage');
	}

	public function deleteUserPicture(int $userId): void {
		$this->apirequest("/d2l/api/lp/".self::VERSION_LP."/profile/user/$userId/image", "DELETE");
	}
}
