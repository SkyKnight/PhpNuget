<?php
require_once(dirname(__FILE__)."/../root.php");
require_once(__ROOT__."/settings.php");
require_once(__ROOT__."/inc/commons/smalltextdbapibase.php");
require_once(__ROOT__."/inc/commons/path.php");
require_once(__ROOT__."/inc/db_nugetpackages.php");
require_once(__ROOT__."/inc/nugetreader.php");
require_once(__ROOT__."/inc/phpnugetobjectsearch.php");

class PackageGroup
{
	public $Id;
	public $Version;
}

class SingleResult
{
	public $Id;
	public $Version;
	public $Success;
	public $Reason;
}

function getSslPage($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_REFERER, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

class PackagesApi extends SmallTextDbApiBase
{
	protected function _verifyInsert($db,$item)
	{
		throw new Exception("Operation insert not allowed!");
	}
	
	
	protected function _verifyDelete($db,$keysArray)
	{
		throw new Exception("Operation delete not allowed!");
	}
	
	
	protected function _buildKeysFromRequest($db)
	{
		$result = array();
		$result[]= UrlUtils::GetRequestParam("Id");
		$result[]= UrlUtils::GetRequestParam("Version");
		return $result;
	}
	
	protected function _buildEntityFromRequest($db)
	{
		$userEntity = new UserEntity();
		$error = true;
		foreach($db->GetAllColumns() as $row)
		{
			if(UrlUtils::ExistRequestParam($row) && strtolower($row)!="dependencies"){
				$userEntity->$row =UrlUtils::GetRequestParam($row);
			}
		}
		
		return $userEntity;
	}
	
	protected function _isMatch($keyArray,$item)
	{
		return $keyArray[0] == $item->Id && $keyArray[1] == $item->Version;
	}
	
	protected function _verifyUpdate($db,$old,$new)
	{	
		if(!array_key_exists ("UserId",$_SESSION)){
			throw new Exception("Not logged in!");
		}
		$user = null;
		global $loginController;
		//var_dump($loginController);die();
		$udb = new UserDb();
		$user = $udb->GetByUserId($loginController->UserId);
		
		
		if(!$loginController->Admin){
			if($user->Id!=$old->UserId){
				throw new Exception("Operation not allowed with current rights!");
			}
		}
		
		$new->Id = $old->Id;
		$new->Version = $old->Version;
		$new->Created = $old->Created;
		$new->Dependencies = $old->Dependencies;
		$new->DownloadCount = $old->DownloadCount;
		$new->IsLatestVersion = $old->IsLatestVersion;
		$new->IsAbsoluteLatestVersion = $old->IsAbsoluteLatestVersion;
		$new->LastUpdated = Utils::FormatToIso8601Date();
		$new->PackageHash = $old->PackageHash;
		$new->PackageHashAlgorithm = $old->PackageHashAlgorithm;
		$new->PackageSize = $old->PackageSize;
		$new->LicenseNames = $old->LicenseNames;
		$new->LicenseReportUrl = $old->LicenseReportUrl;
		$new->TargetFramework = $old->TargetFramework;
		$new->Owners = $old->Owners;
		
	}
	
	protected function _openDb()
	{
		return new NuGetDb();
	}
	
	public function dogetbyquery()
	{
		$query = UrlUtils::GetRequestParam("Query");
		$doGroup = UrlUtils::GetRequestParamOrDefault("DoGroup","false");
		
		global $loginController;
		
		if(!$loginController->Admin){
			if(strlen($query)>0){
				$query = " and (".$query.")";
			}
			$udb = new UserDb();
			$user = $udb->GetByUserId($loginController->UserId);
			$query = "(UserId eq '".$user->Id."')".$query;
		}
		$query.=" orderBy Title asc, Version desc";
		if($doGroup=="true")
			$query.=" groupby Id";
		
		
		$this->_preExecute();
		$pg= $this->_getPagination();
		$db = $this->_openDb();
		$os = new PhpNugetObjectSearch();
		
		$os->Parse($query,$db->GetAllColumns());
		$count = sizeof($db->GetAllRows(999999,0,$os));
		$allRows = $db->GetAllRows($pg->Top,$pg->Skip,$os);
		
		//$allRows = $os->DoSort($allRows,NuGetDb::RowTypes());
		ApiBase::ReturnSuccess($allRows,"","",$count);
	}
	
	protected function _preExecute()
	{
		UrlUtils::InitializeJsonInput();
	}
	
	public function dodownload()
	{
		$tempFile ="";
		try{
			$this->_preExecute();
			$url = UrlUtils::GetRequestParam("Url");
			$id = UrlUtils::GetRequestParam("Id");
			$version = UrlUtils::GetRequestParam("Version");
			
			if($id==null || $url==null || $version==null){
				throw new Exception("Missing data");
			}
			global $loginController;
			
			if(!$loginController->Admin){
				throw new Exception("Unauthorized");
			}
			
			$url = str_replace("@ID",$id,$url);
			$url = str_replace("@VERSION",$version,$url);
			
			$nupackage = getSslPage($url);
			$tempFile = Utils::WriteTemporaryFile($nupackage);
			
			$udb = new UserDb();
			$user = $udb->GetByUserId($loginController->UserId);
			$baseUrl = UrlUtils::CurrentUrl(Settings::$SiteRoot);
			$nugetReader = new NugetManager();
			
			$parsedNuspec = $nugetReader->LoadNuspecFromFile($tempFile);
			
			$parsedNuspec->UserId=$user->Id;
			$nuspecData = $nugetReader->SaveNuspec($tempFile,$parsedNuspec);
		}catch(Exception $ex){
			if(file_exists($tempFile))
			unlink($tempFile);
			ApiBase::ReturnError($ex->getMessage(),500);
		}
		if(file_exists($tempFile))unlink($tempFile);
		ApiBase::ReturnSuccess(null);
	}
	
	public function dorefreshpackages()
	{
		$results =array();
		$i = 0;
		try{
			$this->_preExecute();
			
			global $loginController;
			
			if(!$loginController->Admin){
				throw new Exception("Unauthorized");
			}
			
			$files = scandir(Settings::$PackagesRoot);
			$udb = new UserDb();
			$user = $udb->GetByUserId($loginController->UserId);
			
			foreach($files as $file){
				$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
				if($ext == "nupkg"){
					$m =$this->_loadNupkg(Path::Combine(Settings::$PackagesRoot,$file),$user->Id);
					if(!$m->Success){
						$results[]=$m;
					}else{
						$i++;
					}
				}
			}
			if(sizeof($results)>0){
				$message = "Refreshed ".$i." packages over ".sizeof($results).".";
				ApiBase::ReturnErrorData($results,"","",sizeof($results),$message,500);
			}else{
				$message = "Refreshed ".$i." packages.";
				ApiBase::ReturnSuccess($message);
			}
		}catch(Exception $ex){
			$message = "Refreshed ".$i." packages over ".sizeof($results).".";
			ApiBase::ReturnError($message."\r\n".$ex->getMessage(),500);
		}
	}
	
	private function _loadNupkg($file,$userId)
	{
		$r = new SingleResult();
		$r->Success= true;
		try{
			$nugetReader = new NugetManager();
			$parsedNuspec = $nugetReader->LoadNuspecFromFile($file);
			$r->Id= $parsedNuspec->Id;
			$r->Version= $parsedNuspec->Version;
			$parsedNuspec->UserId=$userId;
			$nuspecData = $nugetReader->SaveNuspec($file,$parsedNuspec);
		}catch(Exception $ex){
			$r->Success = false;
			$r->Reason = $ex->getMessage();
		}
		return $r;
	}
}
?>