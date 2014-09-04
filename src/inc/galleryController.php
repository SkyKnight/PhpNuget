<?php

require_once(__DIR__."/../root.php");
require_once(__ROOT__."/settings.php");
require_once(__ROOT__."/inc/db_nugetPackages.php");
require_once(__ROOT__."/inc/commons/url.php");

class GalleryController
{
	public function ReadList()
	{
		$ndb = new NuGetDb();
		$queryString = UrlUtils::GetRequestParam("searchQuery","post");
		if($queryString==null){
			return $ndb->GetAllRows(Settings::$ResultsPerPage);
		}
		return array();
	}
}
?>