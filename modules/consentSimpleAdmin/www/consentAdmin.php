<?php
/*
 * consentSimpleAdmin - Simple Consent administration module
 *
 * This module is a simplification of the danish consent administration module.
 *
 * @author Andreas Åkre Solberg <andreas.solberg@uninett.no>
 * @author Mads Freen - WAYF
 * @author Jacob Christiansen - WAYF
 * @package simpleSAMLphp
 * @version $Id$
 */


// Get config object
$config = SimpleSAML_Configuration::getInstance();
$consentconfig = SimpleSAML_Configuration::getConfig('module_consentSimpleAdmin.php');

// Get session object
$session = SimpleSAML_Session::getInstance();

$as = $consentconfig->getValue('auth');
if (!$session->isValid($as)) {
	SimpleSAML_Auth_Default::initLogin($as, SimpleSAML_Utilities::selfURL());
}


// Get user ID
$userid_attributename = $consentconfig->getValue('userid', 'eduPersonPrincipalName');
$userids = ($session->getAttribute($userid_attributename));
		
if (empty($userids)) {
	throw new Exception('Could not generate useridentifier for storing consent. Attribute [' .
		$userid_attributename . '] was not available.');
}

$userid = $userids[0];

// Get metadata storage handler
$metadata = SimpleSAML_Metadata_MetaDataStorageHandler::getMetadataHandler();

// Get all attributes
$attributes = $session->getAttributes();

/*
 * Get IdP id and metadata
 */
if($session->getIdP() != null) {
	// From a remote idp (as bridge)
	$idp_entityid = $session->getIdP();
	$idp_metadata = $metadata->getMetaData($idp_entityid, 'saml20-idp-remote');
} else {
	// from the local idp
	$idp_entityid = $metadata->getMetaDataCurrentEntityID('saml20-idp-hosted');
	$idp_metadata = $metadata->getMetaData($idp_entityid, 'saml20-idp-hosted');
}
 
SimpleSAML_Logger::debug('consentAdmin: IdP is ['.$idp_entityid . ']');

$source = $idp_metadata['metadata-set'] . '|' . $idp_entityid;


// Parse consent config
$consent_storage = sspmod_consent_Store::parseStoreConfig($consentconfig->getValue('store'));

// Calc correct user ID hash
$hashed_user_id = sspmod_consent_Auth_Process_Consent::getHashedUserID($userid, $source);



// Check if button with withdraw all consent was clicked.
if (array_key_exists('withdraw', $_REQUEST)) {
	
	SimpleSAML_Logger::info('consentAdmin: UserID ['.$hashed_user_id . '] has requested to withdraw all consents given...');
	
	$consent_storage->deleteAllConsents($hashed_user_id);
	
}



// Get all consents for user
$user_consent_list = $consent_storage->getConsents($hashed_user_id);

$consentServices = array();
foreach($user_consent_list AS $c) $consentServices[$c[1]] = 1;

SimpleSAML_Logger::debug('consentAdmin: no of consents [' . count($user_consent_list) . '] no of services [' . count($consentServices) . ']');

// Init template
$t = new SimpleSAML_XHTML_Template($config, 'consentSimpleAdmin:consentadmin.php');

$t->data['consentServices'] = count($consentServices);
$t->data['consents'] = count($user_consent_list);


$t->show();
?>
