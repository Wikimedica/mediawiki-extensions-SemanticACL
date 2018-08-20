<?php

/**
 * SemanticACL extension - Allows per-page read and edit restrictions to be set with properties.
 *
 * @link https://www.mediawiki.org/wiki/Extension:SemanticACL Documentation
 *
 * @file SemanticACL.php
 * @ingroup Extensions
 * @defgroup SemanticACL
 * @package MediaWiki
 * @author Werdna (Andrew Garrett)
 * @copyright (C) 2011 Werdna
 * @license https://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

// Ensure that the script cannot be executed outside of MediaWiki.
if ( !defined( 'MEDIAWIKI' ) ) {
    die( 'This is an extension to MediaWiki and cannot be run standalone.' );
}

// Display extension properties on MediaWiki.
$wgExtensionCredits['semantic'][] = array(
	'path' => __FILE__,
	'name' => 'Semantic ACL',
	'author' => array(
		'Andrew Garrett',
		'...'
	),
	'descriptionmsg' => 'sacl-desc',
	'url' => 'https://www.mediawiki.org/wiki/Extension:SemanticACL',
	'license-name' => 'GPL-2.0-or-later'
);

// Register extension messages and other localisation.
$wgMessagesDirs['SemanticACL'] = __DIR__ . '/i18n';

// Register extension hooks.
$wgHooks['userCan'][] = 'saclGetPermissionErrors';
$wgHooks['smwInitProperties'][] = 'saclInitProperties';
$wgHooks['ParserFetchTemplate'][] = 'saclCheckTemplatePermission';
$wgHooks['BeforeParserFetchFileAndTitle'][] = 'saclCheckFilePermission';
// Create extension's permissions
$wgGroupPermissions['sysop']['sacl-exempt'] = true;
$wgAvailableRights[] = 'sacl-exempt';

// Initialise predefined properties
function saclInitProperties() {
	// Read restriction properties
	SMWDIProperty::registerProperty( '___VISIBLE', '_str',
					wfMessage('sacl-property-visibility')->inContentLanguage()->text() );
	SMWDIProperty::registerProperty( '___VISIBLE_WL_GROUP', '_str',
					wfMessage('sacl-property-visibility-wl-group')->inContentLanguage()->text() );
	SMWDIProperty::registerProperty( '___VISIBLE_WL_USER', '_wpg',
					wfMessage('sacl-property-visibility-wl-user')->inContentLanguage()->text() );

	SMWDIProperty::registerPropertyAlias( '___VISIBLE', 'Visible to' );
	SMWDIProperty::registerPropertyAlias( '___VISIBLE_WL_GROUP', 'Visible to group' );
	SMWDIProperty::registerPropertyAlias( '___VISIBLE_WL_USER', 'Visible to user' );

	// Write restriction properties
	SMWDIProperty::registerProperty( '___EDITABLE', '_str',
					wfMessage('sacl-property-editable')->inContentLanguage()->text() );
	SMWDIProperty::registerProperty( '___EDITABLE_WL_GROUP', '_str',
					wfMessage('sacl-property-editable-wl-group')->inContentLanguage()->text() );
	SMWDIProperty::registerProperty( '___EDITABLE_WL_USER', '_wpg',
					wfMessage('sacl-property-editable-wl-user')->inContentLanguage()->text() );

	SMWDIProperty::registerPropertyAlias( '___EDITABLE', 'Editable by' );
	SMWDIProperty::registerPropertyAlias( '___EDITABLE_WL_GROUP', 'Editable by group' );
	SMWDIProperty::registerPropertyAlias( '___EDITABLE_WL_USER', 'Editable by user' );

	return true;
}

/**
 * Also works with galleries.
 * */
function saclCheckFilePermission ($parser, $nt, &$options, &$descQuery) {
	
	if(getPermissions($nt, '___VISIBLE', RequestContext::getMain()->getUser())){
		return true; // The user is allowed to view that file.
	}
	
	$options['broken'] = true; // Show a broken file link.
	
	return false;
}

function saclCheckTemplatePermission ($parser, $title, $rev, &$text, &$deps) {
//function saclCheckTemplatePermission ($parser, $title, &$skip, $id) {
	
	if(getPermissions($title, '___VISIBLE', RequestContext::getMain()->getUser())){
		return true; // User is allowed to view that template.
	}
	
	global $wgHooks;
	
	$hookName = 'saclCheckTemplatePermission';
	if($hookIndex = array_search($hookName, $wgHooks['ParserFetchTemplate']) === false) {
		throw new Exception('Function name could no be found in hook.'); // This would only happen with a code refactoring mistake.
	}
	
	// Since we will be rendering wikicode, unset the hook to prevent a recursive permission error on templates.
	unset($wgHooks['ParserFetchTemplate'][$hookIndex]);
	
	$text = wfMessage(RequestContext::getMain()->getUser()->isAnon() ? 'sacl-template-render-denied-anonymous' : 'sacl-template-render-denied-registered')->plain();
	
	$wgHooks['ParserFetchTemplate'] = $hookName; // Reset the hook.
	
	return false;
}

/**
 * This hook is also triggered when displaying search results.
 * */
function saclGetPermissionErrors( $title, $user, $action, &$result ) {

	// The prefix for the whitelisted group and user properties
	// Either ___VISIBLE or ___EDITABLE
	$prefix = '';

	if ( $action == 'read' ) {
		$prefix = '___VISIBLE';
	} else {
		$prefix = '___EDITABLE';
	}
	
	if(!getPermissions($title, $prefix, $user))
	{
		$result = false;
		return false;
	}
		
	return true;
}

function getPermissions($title, $prefix, $user)
{
	global $smwgNamespacesWithSemanticLinks;
	
	if(!isset($smwgNamespacesWithSemanticLinks[$title->getNamespace()]) || !$smwgNamespacesWithSemanticLinks[$title->getNamespace()]) {
		return true; // No need to check permissions on namespaces that do not support SMW.
	}
	
	// Failsafe: Some users are exempt from Semantic ACLs
	if ( $user->isAllowed( 'sacl-exempt') ) {
		return true;
	}
	
	$store = smwfGetStore();
	$subject = SMWDIWikiPage::newFromTitle( $title );
	
	$property = new SMWDIProperty($prefix);
	$aclTypes = $store->getPropertyValues( $subject, $property );
		
	foreach( $aclTypes as $valueObj ) {
		$value = strtolower($valueObj->getString());

		if ( $value == 'users' ) {
			if ( $user->isAnon() ) {
				return false;
			}
		} elseif ( $value == 'whitelist' ) {
			$isWhitelisted = false;

			$groupProperty = new SMWDIProperty( "{$prefix}_WL_GROUP" );
			$userProperty = new SMWDIProperty( "{$prefix}_WL_USER" );
			$whitelistValues = $store->getPropertyValues( $subject, $groupProperty );

			foreach( $whitelistValues as $whitelistValue ) {
				$group = strtolower($whitelistValue->getString());

				if ( in_array( $group, $user->getEffectiveGroups() ) ) {
					$isWhitelisted = true;
					break;
				}
			}

			$whitelistValues = $store->getPropertyValues( $subject, $userProperty );

			foreach( $whitelistValues as $whitelistValue ) {
				$title = $whitelistValue->getTitle();

				if ( $title->equals( $user->getUserPage() ) ) {
					$isWhitelisted = true;
				}
			}

			if ( ! $isWhitelisted ) {
				return false;
			}
		} elseif ( $value == 'public' ) {
			return true;
		}
	}

	return true;
}
