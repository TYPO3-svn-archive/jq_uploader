<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2011 Ulfried Herrmann <herrmann.at.die-netzmacher.de>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
 * [CLASS/FUNCTION INDEX of SCRIPT]
 *
 * Hint: use extdeveval to insert/update function index above.
 */

require_once(PATH_tslib.'class.tslib_pibase.php');


/**
 * Plugin 'jQuery Uploader' for the 'jq_uploader' extension.
 *
 * @author	Ulfried Herrmann <herrmann.at.die-netzmacher.de>
 * @package	TYPO3
 * @subpackage	tx_jquploader
 */
class tx_jquploader_pi1 extends tslib_pibase {
	public    $prefixId      = 'tx_jquploader_pi1';                // Same as class name
	public    $scriptRelPath = 'pi1/class.tx_jquploader_pi1.php';  // Path to this script relative to the extension dir.
	public    $extKey        = 'jq_uploader';                      // The extension key.
	protected $logMode       = NULL;                               // devlog mode
	protected $messageStack  = array(                              // messages to be displayed
		'error'       => array(),
		'warning'     => array(),
		'ok'          => array(),
		'information' => array(),
		'notice'      => array(),
	);
	protected $paramName     = 'files';                            // name of upload input
	protected $terminate     = FALSE;                              // if true no more messages can be added (setted in runtime)
	protected $redirectUrl   = '';                                 // url for redirect after complete finishing
	protected $numImg        = 0;                                  // number of uploaded images

	/**
	 * Main method of your PlugIn
	 *
	 * @param	string		$content: The content of the PlugIn
	 * @param	array		$conf: The PlugIn Configuration
	 * @return	The content that should be displayed on the website
	 */
	function main($content, $conf) {
			//  prepare plugin config
		$this->prepareConfig($conf);
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();


			//  display messages if any from previous step
		if (empty ($this->piVars['ajaxUpload'])) {
			$content .= $this->flushMessageStack();
		}


			//  check for record uid
		$this->checkRecordUid();

			//  owner check
		if ($this->terminate === FALSE AND !empty ($this->conf['table.']['check_owner'])) {
			$this->checkOwner();
		}

			//  current
		$this->currentUrl  = $this->getCurrentUrl();
			//  url next step
		$this->redirectUrl = $this->getRedirectUrl();

			//	load current records data (images and image caption)
		if ($this->terminate === FALSE) {
			$this->getData();
		}

			//  check for AJAX upload
		if (!empty ($this->piVars['ajaxUpload'])) {
			if ($this->logMode == -2) {
				t3lib_div::devlog('AJAX [upload]: files detected', $this->prefixId, -1, $_FILES);
			}

			if (!empty ($_FILES)) {
				$this->setData();
				return;
			}
		}

			//  update
		if ($this->terminate === FALSE AND !empty ($this->piVars['process']) AND $this->piVars['process'] == 1) {
				//  edit data
			$content .= $this->setData();

				//  ready?
			if ($this->ready === TRUE) {
				$this->redirect();
			}
		}

			//  output message stack
		$messages = $this->getMessageStack();
		if ($this->terminate === FALSE) {
			$content = $messages . $content;
		} else {
			$content = $messages;
			return $content;
		}

		$content .= $this->renderForm();

		return $this->pi_wrapInBaseClass($content);
	}


	// -------------------------------------------------------------------------
	/**
	 * prepare plugin config
	 *
	 * @param	array		$conf: The PlugIn Configuration
	 * @return	void
	 */
	protected function prepareConfig($conf) {
		if (empty ($conf['upload_folder'])) {
			$conf['upload_folder'] =  'uploads/pics/';
		}
			//  check for trailing slash
		if (!preg_match('%\/$%', $conf['upload_folder'])) {
			$conf['upload_folder'] .= '/';
		}

			//  read config from flexform and set it to $this->config; if empty use TS config:
			//  Init and get the flexform data of the plugin
		$this->pi_initPIflexForm();
			// Assign the flexform data to a local variable for easier access
		$piFlexForm =& $this->cObj->data['pi_flexform'];
			// Traverse the entire array based on the language and assign each configuration option to $this->lConf array...
		foreach ($piFlexForm['data'] as $sheet => $data ) {
			foreach ($data as $lang => $value) {
				foreach ($value as $key => $val) {
					$conf[$key] = $this->pi_getFFvalue($piFlexForm, $key, $sheet);
				}
			}
		}

		$conf['imagefile_ext'] = $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'];

		$extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
		if (!empty ($extConf['log_enable']) AND isset ($extConf['log_mode'])) {
			$this->logMode = $extConf['log_mode'];
		} else {
			$this->logMode = 999;  //  high value disables logging
		}

		$this->conf = array_merge($conf, $extConf);

			//  debug
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [prepared configuration]', $this->prefixId, 0, $this->conf);
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return array	$images
	 */
	protected function checkRecordUid() {
			//	current record: get from piVars / session of configured plugin
		switch ($this->conf['record']) {
		case 'session':
				//  get record uid from session
			$lConf         =& $this->conf['session.']['input.'];
			$_sessionName  =& $lConf['session_name'];
			$_sessionKey   =& $lConf['session_key'];
#echo '<pre><b>$_sessionName @ ' . __FILE__ . '::' . __LINE__ . ':</b> ' . print_r($_sessionName, 1) . '</pre>';
#$extSessionData = $GLOBALS['TSFE']->fe_user->getKey('ses', 'org_pinboard');
#echo '<pre><b>$extSessionData @ ' . __FILE__ . '::' . __LINE__ . ':</b> ' . print_r($extSessionData, 1) . '</pre>'; exit;

			$extSessionData = $GLOBALS['TSFE']->fe_user->getKey('ses', $_sessionName);
#echo '<pre><b>$extSessionData @ ' . __FILE__ . '::' . __LINE__ . ':</b> ' . print_r($extSessionData, 1) . '</pre>'; exit;
			$this->recordUid = $extSessionData[$_sessionKey];
			if (!empty ($this->conf['session.']['input.']['use_md5'])) {
					//  get integer uid from md5 uid
				$this->recordUidMd5toInt();
			}
				//  debug
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [session]', $this->prefixId, -1, $extSessionData);
			}
			break;
		case 'piVars':
				//  debug
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [piVars]', $this->prefixId, -1, $this->piVars);
			}
				//  get record uid from piVars
		$lConf        =& $this->conf['piVars.']['input.'];
		$_piVarsTable =& $lConf['table_name'];
		$_piVarsField =& $lConf['field_name'];
		$_piVars = t3lib_div::_GP($_piVarsTable);
			$this->recordUid = $_piVars[$_piVarsField];
			if (!empty ($this->conf['piVars.']['input.']['use_md5'])) {
					//  get integer uid from md5 uid
				$this->recordUidMd5toInt();
			}
			break;
		default:
			if ($this->logMode <= 2) {
				t3lib_div::devlog('ERROR [INVALID CALL]: ' . $this->pi_getLL('msg_noEntryConfig') . ' - Abort.', $this->prefixId, 2);
			}
			break;
		}
		if (empty ($this->recordUid)) {
			if ($this->logMode <= 2) {
				t3lib_div::devlog('ERROR [INVALID CALL]: ' . $this->pi_getLL('msg_noEntryId') . ' - Abort.', $this->prefixId, 2);
			}
				//  error message + exit
			$this->setMessageStack('warning', $this->pi_getLL('msg_noEntryId'), $terminate = TRUE);
			return;
		}




		$this->recordUid = (int)$this->recordUid;
			//  debug
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [RECORD UID]: ' . $this->recordUid . '.', $this->prefixId, -1);
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return array	$images
	 */
	protected function recordUidMd5toInt() {
		$lConf =& $this->conf['table.'];

		$select_fields = $lConf['key_field'] . ' AS uid';
		$from_table    = $this->conf['table'];
		$where_clause  = 'md5(' . $lConf['key_field'] . ')' . ' = ' . $GLOBALS['TYPO3_DB']->fullQuoteStr($this->recordUid, $from_table);
		$groupBy       = '';
		$orderBy       = '';
		$limit         = '0, 1';

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select_fields, $from_table, $where_clause, $groupBy, $orderBy, $limit);
		$err = $GLOBALS['TYPO3_DB']->sql_error();
		if (!empty ($err)) {
			if ($this->logMode <= 3) {
				$sql = $GLOBALS['TYPO3_DB']->SELECTquery($select_fields, $from_table, $where_clause, $groupBy, $orderBy, $limit);
				t3lib_div::devlog('ERROR [RUNTIME]: sql statement record uid from md5 failed - Abort.', $this->prefixId, 3, array('error' => $err, 'sql' => $sql,));
			}
					//  error message + exit
			$this->setMessageStack('error', 'Error. See DRS for details!', $terminate = TRUE);
			return;
		}

		$ftc = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
		$num = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		$GLOBALS['TYPO3_DB']->sql_free_result($res);
		if ($num == 1) {
			$this->recordUid = $ftc['uid'];
				//  debug
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [RECORD UID] from MD5: ' . $this->recordUid . '.', $this->prefixId, -1);
			}
		} else {
			if ($this->logMode <= 3) {
				t3lib_div::devlog('ERROR [RECORD UID] from MD5 not found - Abort.', $this->prefixId, 3);
			}
					//  error message + exit
			$this->setMessageStack('error', 'Error. See DRS for details!', $terminate = TRUE);
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return array	$images
	 */
	protected function checkOwner() {
		$check = FALSE;
		$owner = 0;

		$lConf =& $this->conf['table.'];
		$select_fields = $lConf['check_owner.']['field'] . ' AS owner';
		$from_table    = $this->conf['table'];
		$where_clause  = $lConf['key_field'] . ' = ' . (int)$this->recordUid;
		$groupBy       = '';
		$orderBy       = '';
		$limit         = '0, 1';

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select_fields, $from_table, $where_clause, $groupBy, $orderBy, $limit);
		$err = $GLOBALS['TYPO3_DB']->sql_error();
		if (!empty ($err)) {
			if ($this->logMode <= 3) {
				$sql = $GLOBALS['TYPO3_DB']->SELECTquery($select_fields, $from_table, $where_clause, $groupBy, $orderBy, $limit);
				t3lib_div::devlog('ERROR [RUNTIME]: sql statement check owner failed - Abort.', $this->prefixId, 3, array('error' => $err, 'sql' => $sql,));
			}
					//  error message + exit
			$this->setMessageStack('error', 'Error. See DRS for details!', $terminate = TRUE);
			return;
		}

		$ftc = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
		$num = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		$GLOBALS['TYPO3_DB']->sql_free_result($res);
		if ($num == 1 AND (int)$ftc['owner'] == $GLOBALS['TSFE']->fe_user->user['uid']) {
			$check = TRUE;
				//  debug
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [USERS ACCESS]: Current user is owner of record called for editing.', $this->prefixId, -1);
			}
		}

		if ($check === FALSE) {
			if ($this->logMode <= 3) {
				t3lib_div::devlog('ERROR [USERS ACCESS]: Current user is not owner of record called for editing - Abort.', $this->prefixId, 3, array('owner' => $owner, 'user' => $GLOBALS['TSFE']->fe_user->user['uid'],));
			}
				//  error message + exit
			$this->setMessageStack('error', $this->pi_getLL('msg_noAccess'), $terminate = TRUE);
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return array	$images
	 */
	protected function getData() {
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [trace]: getData()', $this->prefixId, -1);
		}

		$lConf =& $this->conf['table.'];
		$select_fields = array(
			$lConf['image.']['field'] . ' AS image',
			$lConf['image.']['caption'] . ' AS caption',
		);
		$select_fields = implode(', ', $select_fields);
		$from_table    = $this->conf['table'];
		$where_clause  = $lConf['key_field'] . ' = ' . $this->recordUid;
		$groupBy       = '';
		$orderBy       = '';
		$limit         = '0, 1';

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($select_fields, $from_table, $where_clause, $groupBy, $orderBy, $limit);
		$err = $GLOBALS['TYPO3_DB']->sql_error($res);
		if (!empty ($err)) {
			if ($this->logMode <= 3) {
				$sql = $GLOBALS['TYPO3_DB']->SELECTquery($select_fields, $from_table, $where_clause, $groupBy, $orderBy, $limit);
				t3lib_div::devlog('ERROR [RUNTIME]: sql statement get data failed - Abort.', $this->prefixId, 3, array('error' => $err, 'sql' => $sql,));
			}
					//  error message + exit
			$this->setMessageStack('error', 'Error. See DRS for details!', $terminate = TRUE);
			return;
		}

		$images = array();
			//  fetch current record
		$num = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		$ftc = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
		$GLOBALS['TYPO3_DB']->sql_free_result($res);
		if ($num == 1) {
			if (!empty ($ftc['image'])) {
				$images = array(
					'image'   => $ftc['image'],
					'caption' => $ftc['caption'],
				);
			} else {
				$images = NULL;
			}
		} else {
			$images = NULL;
		}

			//  debug
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [CURRENT IMAGES] before editig', $this->prefixId, 0, array($images));
		}

		$this->images = $images;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function setData() {
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [trace]: setData()', $this->prefixId, -1);
		}

		$images      = t3lib_div::trimExplode(',', $this->images['image'], TRUE);
		$this->ready = TRUE;  //  if this form is shown again

			//  update sorting
		if (!empty ($this->piVars['update_sorting'])) {
##				//  show form again
##			$this->ready = FALSE;

			$updateSorting = t3lib_div::trimExplode(',', $this->piVars['update_sorting'], TRUE);
			$updateSorting = array_flip($updateSorting);

				//  debug
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [UPDATE SORTING]', $this->prefixId, 0, array('update sorting' => $updateSorting));
			}
		}

			//	delete images
		if (!empty ($this->piVars['image_delete']) AND is_array($this->piVars['image_delete'])) {
			$this->deleteFiles($images, $updateSorting);

				//  debug
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [IMAGE DELETE]', $this->prefixId, 0, array('image delete' => $this->piVars['image_delete']));
			}
		}


			//  resort images and captions
		if (empty ($this->piVars['update_sorting'])) {
			$setImages   =& $images;
			$setCaptions =& $this->piVars['image_caption'];
		} else {
		##	$this->ready = FALSE;
			$setImages   = array();
			$setCaptions = array();
			foreach ($updateSorting as $usKey => $usVal) {
				$setImages[]   = $images[$usKey];
				$setCaptions[] = $this->piVars['image_caption'][$usKey];
			}
		}


			//  add images
			//  :TODO: see http://www.pi-phi.de/177.html
		$this->storeFiles($setImages);


			//  prepare + store values
		$this->updateFileData($setImages, $setCaptions);
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function deleteFiles(&$images, &$updateSorting) {
			//  show form again
		$this->ready = FALSE;

		$_unlinkSuccess = array();
		$_unlinkError   = array();
		foreach ($this->piVars['image_delete'] as $dKey => $dVal) {
				//  delete image file
			$_file = $this->conf['upload_folder'] . $images[(int)$dKey];
			$res   = @unlink($_file);
			if ($res) {
				$_unlinkSuccess[] = basename($_file);
				if ($this->logMode <= -1) {
					t3lib_div::devlog('IMAGE FILES [DELETE OK]: ' . $_file, $this->prefixId, -1);
				}
			} else {
				$_unlinkError[] = basename($_file);
				if ($this->logMode <= 2) {
					t3lib_div::devlog('IMAGE FILES [DELETE FAILED]: ' . $_file, $this->prefixId, 2);
				}
			}

				//  remove from image collection
			unset ($images[$dKey]);
				//  remove from sorting collection
			unset ($updateSorting[$dKey]);
				//  remove from caption collection
			unset ($this->piVars['image_caption'][$dKey]);
		}

			//  build messages for stack
		$_numUnlinkSuccess = count($_unlinkSuccess);
		if ($_numUnlinkSuccess > 0) {
			$_files = array();
			$_list  = '<ul>';
			foreach ($_unlinkSuccess as $usVal) {
				$_list .= '<li>' . $usVal . '</li>';
			}
			$_list .= '</ul>';
			$this->setMessageStack('ok', sprintf($this->pi_getLL('msg_successFileDelete'), $_list));
		}
		$_numUnlinkError   = count($_unlinkError);
		if ($_numUnlinkError > 0) {
			$_files = array();
			$_list  = '<ul>';
			foreach ($_numUnlinkError as $ueVal) {
				$_list .= '<li>' . $ueVal . '</li>';
			}
			$_list .= '</ul>';
			$this->setMessageStack('warning', sprintf($this->pi_getLL('msg_errorFileDelete'), $_list));
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function storeFiles(&$setImages) {
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [trace]: storeFiles()', $this->prefixId, -1);
		}

		if (empty ($_FILES) AND count($_FILES) > 0 AND count($this->images) < $this->conf['form.']['max_images']) {
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [UPLOADS]: no files uploaded', $this->prefixId, 0);
			}

			return;
		}

		$_uploads    = array();
		$_uploadInfo = array();
		$_uploadErr  = array(
			0 => 'ok',
			1 => 'ini_size',
			2 => 'form_size',
			3 => 'partial',
		);
	###	foreach ($_FILES[$this->prefixId]['error']['image_upload'] as $fKey => $fVal) {
		$i = 0;
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [_FILES]: loop [' . $i . ']', $this->prefixId, -1, $_FILES[$this->paramName]['error']);
			}
		foreach ($_FILES[$this->paramName]['error'] as $fKey => $fVal) {
			if ($this->logMode == -2) {
				t3lib_div::devlog('DEBUG [_FILES]: loop', $this->prefixId, -1);
			}

			if ($fVal == 4) {  //  UPLOAD_ERR_NO_FILE: skip
				continue;
			} elseif ($fVal == 0) {  //  consider only UPLOAD_ERR_OK
				if ($this->logMode == -2) {
					t3lib_div::devlog('DEBUG [UPLOAD_ERR]: ' . $_FILES[$this->paramName]['name'][$fKey], $this->prefixId, -1);
				}
				$_uploads[$fKey] = array(
					'name'     => $_FILES[$this->paramName]['name'][$fKey],
					'tmp_name' => $_FILES[$this->paramName]['tmp_name'][$fKey],
					'img_size' => getimagesize($_FILES[$this->paramName]['tmp_name'][$fKey]),
				);
			}

				//  catch errors
			$_uploadInfo[$fVal][] = $_FILES[$this->paramName]['name'][$fKey];

			if (!empty ($this->piVars['ajaxUpload'])) {
				if ($this->logMode == -2) {
					t3lib_div::devlog('DEBUG [trace]: call ajaxUploadInfo()', $this->prefixId, -1);
				}
				$this->ajaxUploadInfo($i);
			}

			$i++;
		}
				//  display errors
		foreach ($_uploadInfo as $uiKey => $uiVal) {
			$_list  = '<ul>';
			foreach ($uiVal as $fileName) {
				$_list .= '<li>' . $fileName . '</li>';
			}
			$_list .= '</ul>';
			$severity = ($uiKey == 0 ? 'ok' : 'warning');
			$this->setMessageStack($severity, sprintf($this->pi_getLL('msg_upload_err_' . $_uploadErr[$uiKey]), $_list));

				//  log
			$severity = ($uiKey == 0) ? -1 : 3;
			if ($this->logMode <= $severity) {
				foreach ($uiVal as $fileName) {
					t3lib_div::devlog('FILE UPLOAD [RESULT]: file "' . $fileName . '" ' . $_uploadErr[$uiKey], $this->prefixId, $severity);
				}
			}
		}

		if (count($_uploads) > 0) {
				//  show form again
			$this->ready    = FALSE;
			$this->fileFunc = t3lib_div::makeInstance('t3lib_basicFileFunctions');

				//  check for valid image format and move uploades file
				## @ToDo: allow other and restricted formats
			$_imagefile_ext = t3lib_div::trimExplode(',', $this->conf['imagefile_ext'], TRUE);
			foreach ($_imagefile_ext as $iKey => $iVal) {
				$_imagefile_ext[$iKey] = strtolower($iVal);
			}
			foreach ($_uploads as $uKey => $uVal) {
					//  sort out invalid image format
				if (!is_array($uVal['img_size'])) {
					$this->setMessageStack('warning', sprintf($this->pi_getLL('msg_upload_err_noimage'), $uVal['name']));
					if ($this->logMode <= 3) {
						t3lib_div::devlog('FILE UPLOAD [INVALID IMAGE FORMAT]: file ' . $uVal['name'] . ' skipped!', $this->prefixId, 2);
					}
					continue;
				}
					//  sort out invalid file extensions
				$_fileExt = strtolower(array_pop(t3lib_div::trimExplode('.', $uVal['name'], TRUE)));
				if (!in_array($_fileExt, $_imagefile_ext)) {
						//  sort out invalid image format
					$this->setMessageStack('warning', sprintf($this->pi_getLL('msg_upload_err_wrongext'), $uVal['name']));
					if ($this->logMode <= 3) {
						t3lib_div::devlog('FILE UPLOAD [INVALID FILE FORMAT]: file ' . $uVal['name'] . ' skipped!', $this->prefixId, 2, array('ext' => $_fileExt, 'allowed' => $_imagefile_ext));
					}
					continue;
				}

					//  move uploaded file
				$source       = $uVal['tmp_name'];
				$destination  = $this->fileFunc->cleanFileName($uVal['name']);
				$destination  = strtolower($destination);
				$destination  = $this->fileFunc->getUniqueName($destination, $this->conf['upload_folder'], $dontCheckForUnique = 0);
				$setImages[]  = basename($destination);
				$res = t3lib_div::upload_copy_move($source, $destination);
				if (res) {
					if ($this->logMode == -1) {
						t3lib_div::devlog('DEBUG: FILE UPLOAD [MOVE OK]: file ' . $uVal['name'], $this->prefixId, -1);
					}
				} else {
					## ToDo: msg with kontakt data (email)
					$this->setMessageStack('error', 'Uploaded file storing failed ... sorry!', $uVal['name']);
					if ($this->logMode <= 3) {
						t3lib_div::devlog('FILE UPLOAD [MOVE FAILED]: file ' . $uVal['name'], $this->prefixId, 3);
					}
				}
			}
		}

		$setImages = implode(',', $setImages);
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function ajaxUploadInfo($i) {
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [trace]: ajaxUploadInfo() [' . $i . ']', $this->prefixId, -1, $_FILES);
		}

		if (!isset ($this->ajaxUploadInfo)) {
			$this->ajaxUploadInfo = array();
		}
		$file = array(
			'name'        => basename(stripslashes($_FILES[$this->paramName]['name'][$i])),
			'size'        => intval($_FILES[$this->paramName]['size'][$i]),
			'type'        => $_FILES[$this->paramName]['type'][$i],
			'url'         => '',
			'delete_url'  => '',
			'delete_type' => '',
		);

		$this->ajaxUploadInfo[$i] = $file;
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [ajaxUploadInfo]', $this->prefixId, -1, $this->ajaxUploadInfo);
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function updateFileData($setImages, $setCaptions = FALSE) {
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [trace]: updateFileData()', $this->prefixId, -1);
		}

			//  clean values
		foreach ($setCaptions as $scKey => $scVal) {
			//  line breaks to whitespaces
			$search = array(
				"\n\r", "\r\n", "\n", "\r",
			);
			$replace = array(
				' ', ' ', ' ', ' ',
			);
            
			$scVal = str_replace($search, $replace, $scVal);
			//
			$setCaptions[$scKey] = htmlspecialchars(trim(strip_tags($scVal)));
		}
		$setCaptions = implode(chr(10), $setCaptions);

		if ($this->ready === FALSE OR $setCaptions != $this->images['caption']) {
			$table           = $this->conf['table'];
			$where           = $this->conf['table.']['key_field'] . ' = ' . $this->recordUid;
			$fields_values   = array(
				$this->conf['table.']['image.']['field']   => $setImages,
			);
				//  prevent clearing captions during upload
			if (empty ($this->piVars['ajaxUpload'])) {
				$fields_values[$this->conf['table.']['image.']['caption']] = $setCaptions;
			}
			$no_quote_fields = false;
			$res = $GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, $where, $fields_values, $no_quote_fields);
			$err = $GLOBALS['TYPO3_DB']->sql_error();
			if (!empty ($err)) {
				if ($this->logMode <= 3) {
					$sql = $GLOBALS['TYPO3_DB']->SELECTquery($select_fields, $from_table, $where_clause, $groupBy, $orderBy, $limit);
					t3lib_div::devlog('ERROR [RUNTIME]: sql statement record uid from md5 failed - Abort.', $this->prefixId, 3, array('error' => $err, 'sql' => $sql,));
				}
						//  error message + exit
				$this->setMessageStack('error', 'Error. See DRS for details!', $terminate = TRUE);
				return;
			}

				//  messages
			if (!empty ($this->piVars['update_sorting'])) {
				$this->setMessageStack('ok', $this->pi_getLL('msg_successUpdateSorting'));
			}
			if ($setCaptions != $this->images['caption'] AND empty ($this->piVars['ajaxUpload'])) {
				$this->setMessageStack('ok', $this->pi_getLL('msg_successUpdateCaption'));
			}


				//  result info (JSON response) for AJAX upload
			if (!empty ($this->piVars['ajaxUpload'])) {
				if ($this->logMode == -2) {
					t3lib_div::devlog('AJAX [upload]: JSON response', $this->prefixId, -1, $this->ajaxUploadInfo);
				}

				header('Vary: Accept');
				if (isset($_SERVER['HTTP_ACCEPT']) && (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
					header('Content-type: application/json');
				} else {
					header('Content-type: text/plain');
				}
                ob_clean();
				echo json_encode($this->ajaxUploadInfo);
				exit;
			}


			if ($this->ready === FALSE) {
					//  reload: show result after update/ refresh lock
				$currentUrl = t3lib_div::locationHeaderUrl($this->currentUrl);
				header('Location: ' . $currentUrl);
				exit;
			}
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 * @:TODO: text to locallang
	 * @:TODO: html to template
	 *
 	 * @version 0.1.0
	 */
	protected function renderForm() {
		$content = '';


			//  get template file
		$templateCode = $this->cObj->fileResource($this->conf['template.']['fileHTML']);
		if (empty ($templateCode)) {
				//	log + abort
			if ($this->logMode <= 3) {
				t3lib_div::devlog('TEMPLATE ERROR: no template found. ABORT', $this->prefixId, 3, array('missed template' => $this->conf['template.']['fileHTML']));
			}
			return;
		}

		$imageList = $this->getImageList($templateCode);
		$markerArray = array(
			'###INFOBOX###'        => $this->getHeadingInfo(),
			'###ACTION###'         => $this->currentUrl,
			'###ACTION###'         => $this->currentUrl,
			'###METHOD###'         => 'post',
			'###PREFIXID###'       => $this->prefixId,
			'###FILE_CAPTION_LABEL###' => $this->pi_getLL('filelist_imagecaption'),

			'###BUTTON_ADD###'     => $this->pi_getLL('button_add'),
			'###BUTTON_START###'   => $this->pi_getLL('button_start'),
			'###BUTTON_CANCEL###'  => $this->pi_getLL('button_cancel'),
			'###BUTTON_DELETE###'  => $this->pi_getLL('button_delete'),
			'###BUTTON_SUBMIT###'  => $this->pi_getLL('button_submit'),
			'###BUTTON_SAVE###'    => $this->pi_getLL('button_save'),

			'###DELETEICON###'     => $this->cObj->cObjGetSingle($this->conf['files.']['delete'],   $this->conf['files.']['delete.']),
			'###DELETEICON_ALT###' => $this->pi_getLL('deleteicon_alttext'),
			'###SORTICON###'       => $this->cObj->cObjGetSingle($this->conf['files.']['sortable'], $this->conf['files.']['sortable.']),
			'###SORTICON_ALT###'   => $this->pi_getLL('sorticon_alttext'),
			'###DELETE_CONFIRM###' => $this->pi_getLL('delete_confirm'),
			'###JS_MAXFILES###'    => ($this->conf['form.']['max_images'] - $this->numImg),
			'###JS_INCLUDE###'     => $this->getJSinclude(),
		);

			// Get template
		$template = $this->cObj->getSubpart($templateCode, '###TEMPLATE_FORM###');
		$template = $this->cObj->substituteSubpart($template, '###TEMPLATE_FORM_FILELIST###', $imageList);
		$content .= $this->cObj->substituteMarkerArray($template, $markerArray);

		return $content;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 *
 	 * @version 0.1.0
	 */
	protected function getHeadingInfo() {
		$_format     = nl2br($this->pi_getLL('heading_info'));
		$_max_images = (int)$this->conf['form.']['max_images'];
		$_linkOpen   = '<a href="' . $this->redirectUrl . '">';
		$_linkClose  = '</a>';
		$_fileExt    = strtoupper(strtr($this->conf['imagefile_ext'], array(',' => ', ')));
		$_fileSize   = t3lib_div::getMaxUploadFileSize() / 1024 . ' MB';
		$content     = sprintf($_format, $_max_images, $_linkOpen, $_linkClose, $_fileExt, $_fileSize);

		return $content;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function getImageList(&$templateCode) {
		$content = '';

			//  images
		if ($this->images === FALSE) {
			return $content;
		}

			//  debug
		if ($this->logMode == -2) {
			t3lib_div::devlog('DEBUG [Image list]', $this->prefixId, -1, $this->images);
		}

		$templateList = $this->cObj->getSubpart($templateCode, '###TEMPLATE_FORM_FILELIST###');
		$templateItem = $this->cObj->getSubpart($templateCode, '###TEMPLATE_FORM_FILELIST_ITEM###');

		$imageFiles   = t3lib_div::trimExplode(',', $this->images['image'], TRUE);
		$imageCaption = t3lib_div::trimExplode(chr(10), $this->images['caption'], FALSE);
		$this->numImg = count($imageFiles);

		$itemContent = '';
		if ($this->numImg > 0) {
			$content .= $this->getUploadedInfo();

		###	$content .= '
		###		<ul id="' . $this->prefixId . '_sortable">';
			$index       = 0;
			$iConf       = array(
				'file.' => array(
					'maxW' => $this->conf['form.']['maxW'],
					'maxH' => $this->conf['form.']['maxH'],
				),
			);
			foreach ($imageFiles as $iKey => $iVal) {
				$iConf['file'] = $this->conf['upload_folder'] . $iVal;
				$iRsrc         = $this->cObj->IMG_RESOURCE($iConf);
				$tRsrc         = $this->cObj->cObjGetSingle($this->conf['files.']['transgif'], $this->conf['files.']['transgif.']);
				$markerArray = array(
					'###FILELIST_INDEX###'         => $index,
					'###FILELIST_FILENAME###'      => $iVal,
					'###FILELIST_CLEARGIF###'      => '<img src="' . $tRsrc . '" class="filelist-image"
															style="background-image: url(' . $iRsrc . '); height: ' . ($this->conf['form.']['maxH'] + 30) . 'px; width: ' . ($this->conf['form.']['maxW'] + 10) . 'px;" />',
					'###FILELIST_CAPTION_VALUE###' => htmlspecialchars(trim($imageCaption[$iKey]), ENT_COMPAT, 'UTF-8', $double_encode = FALSE),
				);
					//  debug
				if ($this->logMode == -2) {
					t3lib_div::devlog('DEBUG [Image resource]: ' . $iRsrc, $this->prefixId, -1);
				}

### @todo: make clear.gif configurable in TS
				$itemContent .= $this->cObj->substituteMarkerArray($templateItem, $markerArray);

				$index++;
			}
			$content .= $this->cObj->substituteSubpart($templateList, '###TEMPLATE_FORM_FILELIST_ITEM###', $itemContent);
		}

		return $content;
	}


	// -------------------------------------------------------------------------
	/**
	 * Include JS files
	 *
	 * Including by TypoScript (e.g. includeJS or includeJSFooterlibs)
	 * will lead to wrong order of including
	 *
	 * @return string	   HTML content
	 *
 	 * @version 0.1.1
	 */
	protected function getJSinclude() {
		$content = '';

		foreach ($this->conf['includeJSlibs.'] as $ijsVal) {
			$ijsVal   = str_replace('EXT:jq_uploader/', t3lib_extMgm::siteRelPath($this->extKey), $ijsVal);

			$content .= chr(10) . '<script src="' . $ijsVal . '" type="text/javascript"></script>';
		}

		return $content;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 *
 	 * @version 0.1.0
	 */
	protected function getUploadedInfo() {
		$content    .= '<p>' . $this->pi_getLL('info_uploadedyet') . '</p>';
		$_format     = nl2br($this->pi_getLL('info_uploadedyetNoJS'));
		$_buttonText = $this->pi_getLL('button_submit');
		$content    .= '<p class="hideIfJQ">' . sprintf($_format, $_buttonText) . '</p>';

		return $content;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function getCurrentUrl() {
		$_piVarsTable  =& $this->conf['piVars.']['input.']['table_name'];
		$_piVarsField  =& $this->conf['piVars.']['input.']['field_name'];
		$_recordUid    = ($this->conf['piVars.']['input.']['use_md5'] ? md5($this->recordUid) : $this->recordUid);
		$urlParameters = array(
			$_piVarsTable . '[' . $_piVarsField . ']' => $_recordUid,
		);
		$currentUrl    = $this->pi_getPageLink($id = $GLOBALS['TSFE']->id, $target = '', $urlParameters);

		return $currentUrl;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
  */
	protected function getRedirectUrl() {
		$lConf         =& $this->conf['piVars.']['output.'];
		$pid           = (int)$this->conf['pid.']['redirect'];
		$uid           = $this->recordUid;
		$uid           = (empty ($lConf['use_md5']) ? $uid : md5($uid));
		$urlParameters = array(
			$lConf['table_name'] . '[' . $lConf['field_name'] . ']' => $uid,
		);
		$redirectUrl   = $this->pi_getPageLink($pid, $target = '', $urlParameters);
		$redirectUrl   = t3lib_div::locationHeaderUrl($redirectUrl);

		return $redirectUrl;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function redirect() {
		$this->cleanMessageStack();
		header('Location: ' . $this->redirectUrl);
		exit;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function setMessageStack($severity, $message, $terminate = FALSE) {
		if ($this->terminate == TRUE) {
			return;
		}
		$this->messageStack[$severity][] = array(
			'message'   => $message,
			'terminate' => $terminate,
		);
		if ($terminate === TRUE) {
				//  terminating message is last one
			$this->terminate = $terminate;
		} else {
				// set message to session
			$GLOBALS['TSFE']->fe_user->setKey('ses', $this->extKey . '_messagestack', $this->messageStack);
			$GLOBALS['TSFE']->storeSessionData(); // Save session
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function getMessageStack() {
		$content = '';
		$this->terminate = FALSE;

		foreach ($this->messageStack as $severity => $messages) {
			if (is_array($messages) AND count($messages) > 0) {
				foreach ($messages as $message) {
					$content .= '
	<div class="typo3-message message-' . $severity . '">
		<div class="message-body">
			' . $message['message'] . '
		</div>
	</div>';
					if (!empty ($message['terminate'])) {
						$this->terminate = TRUE;
						return $content;
					}
				}
			}
		}

		$content = '
<div class="typo3-message-box">' . $content . '</div>';

		return $content;
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return string	   ?
	 */
	protected function flushMessageStack() {
			// get message stack from session
		$messageStackCurrent = $GLOBALS['TSFE']->fe_user->getKey('ses', $this->extKey . '_messagestack');
		if (!empty ($messageStackCurrent) AND is_array($messageStackCurrent)) {
				//  keep message default stack
			$messageStackDefault = $this->messageStack;
				//  output
			$this->messageStack = $messageStackCurrent;
			$content = $this->getMessageStack();
				//  reset
			$this->messageStack = $messageStackDefault;

				// overwrite session with empty array
			$this->cleanMessageStack();

			return $content;
		}
	}


	// -------------------------------------------------------------------------
	/**
	 * Single line description of function getRecord.
	 *
	 * Multi line
	 * description
	 * of function getRecord.
	 *
	 * @return void
	 */
	protected function cleanMessageStack() {
			// overwrite session with empty array
		$GLOBALS['TSFE']->fe_user->setKey('ses', $this->extKey . '_messagestack', array());
		$GLOBALS['TSFE']->storeSessionData();
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/jq_uploader/pi1/class.tx_jquploader_pi1.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/jq_uploader/pi1/class.tx_jquploader_pi1.php']);
}
?>