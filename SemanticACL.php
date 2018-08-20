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
$wgHooks['userCan'][] = 'saclGetUserPermissionsErrors';
$wgHooks['smwInitProperties'][] = 'saclInitProperties';
$wgHooks['ParserFetchTemplate'][] = 'saclParserFetchTemplate';
$wgHooks['BeforeParserFetchFileAndTitle'][] = 'saclBeforeParserFetchFileAndTitle';

// Create extension's permissions
$wgGroupPermissions['sysop']['sacl-exempt'] = true;
$wgAvailableRights[] = 'sacl-exempt';

/** Initialise predefined properties. */
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
 * Called before an image is rendered by Parser to check the image's permissions. Displays a broken link if the 
 * image cannot be viewed.
 * @param Parser $parser Parser object
 * @param Title $nt the image title
 * @param array $options array of options to RepoGroup::findFile. If it contains 'broken'
  as a key then the file will appear as a broken thumbnail
 * @param string $descQuery: query string to add to thumbnail URL
 * */
function saclBeforeParserFetchFileAndTitle($parser, $nt, &$options, &$descQuery) {
	
	// Also works with galleries.
	
	if(hasPermission($nt, 'read', RequestContext::getMain()->getUser(), true)){
		return true; // The user is allowed to view that file.
	}
	
	$options['broken'] = true; // Show a broken file link.
	
	return false;
}

/**
 * Called when the parser fetches a template. Replaces the template with an error message if the user cannot
 * view the template.
 * @param Parser|false $parser Parser object or false
 * @param Title $title Title object of the template to be fetched
 * @param Revision $rev Revision object of the template
 * @param string|false|null $text transclusion text of the template or false or null
 * @param array $deps array of template dependencies with 'title', 'page_id', 'rev_id' keys
 * */
function saclParserFetchTemplate($parser, $title, $rev, &$text, &$deps) {
//function saclCheckTemplatePermission ($parser, $title, &$skip, $id) {
	
	if(hasPermission($title, 'read', RequestContext::getMain()->getUser(), true)){
		return true; // User is allowed to view that template.
	}
	
	global $wgHooks;
	
	$hookName = 'saclParserFetchTemplate';
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
 *  To interrupt/advise the "user can do X to Y article" check.
 * @param Title $title Title object being checked against
 * @param User $user Current user object
 * @param string $action Action being checked
 * @param array|string &$result User permissions error to add. If none, return true. $result can be returned as a single error message key (string), or an array of error message keys when multiple messages are needed (although it seems to take an array as one message key with parameters?).
 * @return bool if the user has permissions to do the action
 * */
function saclGetUserPermissionsErrors( &$title, &$user, $action, &$result ) {
	
	//This hook is also triggered when displaying search results.
	
	return hasPermission($title, $action, $user, false);
}

/** Checks if the provided user can do an action on a page.
 * @param Title $title the title object to check permission on 
 * @param string $action the action the user wants to do
 * @param User $user the user to check permissions for
 * @param bool $disableCaching force the page being checked to be rerendered for each user
 * @return boolean if the user is allowed to conduct the action */
function hasPermission($title, $action, $user, $disableCaching = true)
{
	global $smwgNamespacesWithSemanticLinks;
	
	if(!isset($smwgNamespacesWithSemanticLinks[$title->getNamespace()]) || !$smwgNamespacesWithSemanticLinks[$title->getNamespace()]) {
		return true; // No need to check permissions on namespaces that do not support SemanticMediaWiki
	}
	
	// The prefix for the whitelisted group and user properties
	// Either ___VISIBLE or ___EDITABLE
	$prefix = '';
	
	// Build the semantic property prefix according to the action.
	if ( $action == 'read' ) {
		$prefix = '___VISIBLE';
	} else {
		$prefix = '___EDITABLE';
	}
	
	$store = smwfGetStore();
	$subject = SMWDIWikiPage::newFromTitle( $title );
	
	$property = new SMWDIProperty($prefix);
	$aclTypes = $store->getPropertyValues( $subject, $property );

	if($disableCaching && $aclTypes)
	{
		/* If the parser caches the page, the same page will be returned without consideration for the user viewing the page.
		 * Disable the cache to it gets rendered anew for every user. */
		global $wgParser;
		$wgParser->getOutput()->updateCacheExpiry(0);
		RequestContext::getMain()->getOutput()->enableClientCache(false);
	}
	
	/* Failsafe: Some users are exempt from Semantic ACLs.*/
	if ( $user->isAllowed( 'sacl-exempt') ) {
		return true;
	}
	
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
