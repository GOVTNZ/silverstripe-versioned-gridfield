<?php

/**
 * VersionedGridFieldDetailForm & VersionedGridFieldDetailForm_ItemRequest
 * Allows managing versioned objects through gridfield.
 * See README for details 
 */
class VersionedGridFieldDetailForm extends GridFieldDetailForm {

	public function handleItem($gridField, $request) {
		$controller = $gridField->getForm()->Controller();

		//resetting datalist on gridfield to ensure edited object is in list
		//this was causing errors when the modified object was no longer in the results
		$list = $gridField->getList();
		$list = $list->setDataQuery(new DataQuery($list->dataClass()));

		if(is_numeric($request->param('ID'))) {
			$record = $list->byId($request->param("ID"));
		} else {
			$record = Object::create($gridField->getModelClass());	
		}

		$class = $this->getItemRequestClass();

		$handler = Object::create($class, $gridField, $this, $record, $controller, $this->name);
		$handler->setTemplate($this->template);

		// if no validator has been set on the GridField and the record has a
		// CMS validator, use that.
		if(!$this->getValidator() && method_exists($record, 'getCMSValidator')) {
			$this->setValidator($record->getCMSValidator());
		}

		return $handler->handleRequest($request, DataModel::inst());
	}
	
}

class VersionedGridFieldDetailForm_ItemRequest extends GridFieldDetailForm_ItemRequest {

	private static $allowed_actions = array(
		'edit',
		'view',
		'ItemEditForm',
		'silentPublish'
	);
	
	public function isNew() {
		/**
		 * This check was a problem for a self-hosted site, and may indicate a
		 * bug in the interpreter on their server, or a bug here
		 * Changing the condition from empty($this->ID) to
		 * !$this->ID && !$this->record['ID'] fixed this.
		 */
		if(empty($this->record->ID)) return true;

		if (is_numeric($this->record->ID)) {
			return false;
		}

		return stripos($this->record->ID, 'new') === 0;
	}

	/**
	 * Check if this page has been published.
	 *
	 * @return boolean True if this page has been published.
	 */
	public function isPublished() {
		if ($this->isNew()) {
			return false;
		}

		$record = $this->record;

		return Versioned::get_by_stage($this->baseTable(), 'Live')->byID($record->ID)
			? true
			: false;
	}

	public function baseTable() {
		$record = $this->record;
		$classes = ClassInfo::dataClassesFor($record->ClassName);
		return array_pop($classes);
	}

	public function canPublish() {
		return $this->record->canPublish();
	}

	public function canDeleteFromLive() {
		return $this->canPublish();
	}

	public function stagesDiffer($from, $to) {
		return $this->record->stagesDiffer($from, $to);
	}

	public function canEdit() {
		return $this->record->canEdit();
	}

	public function canDelete() {
		return $this->record->canDelete();
	}

	public function canPreview() {
		$can = false;
		$can = in_array('CMSPreviewable', class_implements($this->record));
		if(method_exists("canPreview", $this->record)) {
			$can = $this->record->canPreview();
		}

		return ($can && !$this->isNew());
	}

	public function getCMSActions() {
		$classname = $this->record->class;

		$minorActions = CompositeField::create()->setTag('fieldset')->addExtraClass('ss-ui-buttonset');
		$actions = new FieldList($minorActions);


		$this->IsDeletedFromStage = $this->getIsDeletedFromStage();
		$this->ExistsOnLive = $this->getExistsOnLive();

		if ($this->isPublished() && $this->canPublish() && !$this->IsDeletedFromStage && $this->canDeleteFromLive()) {
			// "unpublish"
			$minorActions->push(
				FormAction::create('doUnpublish', _t('SiteTree.BUTTONUNPUBLISH', 'Unpublish'))
					->setUseButtonTag(true)->setDescription("Remove this {$classname} from the published site")
					->addExtraClass('ss-ui-action-destructive')->setAttribute('data-icon', 'unpublish')
			);
		}

		if ($this->stagesDiffer('Stage', 'Live') && !$this->IsDeletedFromStage) {
			if($this->isPublished() && $this->canEdit())	{
				// "rollback"
				$minorActions->push(
					FormAction::create('doRollback', 'Cancel draft changes')
						->setUseButtonTag(true)->setDescription(_t('SiteTree.BUTTONCANCELDRAFTDESC', 'Delete your draft and revert to the currently published page'))
				);
			}
		}

		if ($this->canEdit()) {
			if($this->canDelete() && !$this->isNew() && !$this->isPublished()) {
				// "delete"
				$minorActions->push(
					FormAction::create('doDelete', 'Delete')->addExtraClass('action-delete ss-ui-action-destructive')
						->setAttribute('data-icon', 'decline')->setUseButtonTag(true)
				);
			}
		
			// "save"
			$minorActions->push(
				FormAction::create('doSave',_t('CMSMain.SAVEDRAFT','Save Draft'))->setAttribute('data-icon', 'addpage')->setUseButtonTag(true)
			);
		}

		if ($this->canPublish() && !$this->IsDeletedFromStage && !$this->isNew()) {
			// "publish"
			$actions->push(
				FormAction::create('doPublish', _t('SiteTree.BUTTONSAVEPUBLISH', 'Save & Publish'))
					->setUseButtonTag(true)->addExtraClass('ss-ui-action-constructive')->setAttribute('data-icon', 'accept')
			);
		} else {
			$actions->push(
				FormAction::create('doPublish', _t('SiteTree.BUTTONSAVEPUBLISH', 'Save & Publish'))
					->setUseButtonTag(true)->addExtraClass('ss-ui-action-constructive')->setAttribute('data-icon', 'accept')->setDisabled(true)
			);
		}
		// This is a bit hacky, however from what I understand ModelAdmin / GridField dont use the SilverStripe navigator, this will do for now just fine.
		if($this->canPreview()) {
			//Ensure Link method is defined & non-null before allowing preview
			if(method_exists($this->record, 'Link') && $this->record->Link()) {
				$actions->push(
					LiteralField::create("preview",
						sprintf("<a href=\"%s\" class=\"ss-ui-button\" data-icon=\"preview\" target=\"_blank\">%s &raquo;</a>",
							$this->record->Link()."?stage=Stage",
							_t('LeftAndMain.PreviewButton', 'Preview')
						)
					)
				);
			}
		}

		// See if silent publish button should be added.
		$conf = Config::inst();
		if ($conf->get($classname, 'allow_silent_publish')) {
			if ($this->isPublished() && $this->canPublish()) {
				$minorActions->push(
					FormAction::create('silentPublish', _t('Silent Save & Publish', 'Silent Save & Publish'))->setAttribute('data-icon', 'drive-upload')->setUseButtonTag(true)
				);
			}
		}

		$this->extend('updateCMSActions', $actions);

		return $actions;
	}

	public function ItemEditForm() {
		$form = parent::ItemEditForm();
		$actions = $this->getCMSActions();

		$form->setActions($actions);
		return $form;
	}

	public function doSave($data, $form) {
		$oldReadingMode = Versioned::get_reading_mode();

		Versioned::set_reading_mode('Stage.Stage');
		$return = parent::doSave($data, $form);
		Versioned::set_reading_mode($oldReadingMode);

		return $return;
	}

	public function doPublish($data, $form)	{
		$record = $this->record;

		if($record && !$record->canPublish()) {
			return Security::permissionFailure($this);
		}

		$form->saveInto($record);
		$record->writeToStage('Stage');
		$this->gridField->getList()->add($record);

		// use doPublish if it's defined on the object (like SiteTree) which
		// includes extension calls.
		if($record->hasMethod('doPublish')) {
			$record->doPublish();
		} else {
			$record->publish('Stage', 'Live');
		}

		$message = sprintf(
			_t('GridFieldDetailForm.Published', 'Published %s %s'),
			$this->record->singular_name(),
			'"'.Convert::raw2xml($this->record->Title).'"'
		);
		
		$form->sessionMessage($message, 'good');
		return $this->edit(Controller::curr()->getRequest());
	}
	
	/**
	 * Publishes a page silently by preventing it's LastEdited field from being updated.
	 * This allows minor edits (e.g. fixing typos) to be made without the having the page
	 * jump to the top of RSS feeds
	 *
	 * @param array $data Array of submitted form values
	 * @param Form $form The SilverStripe Form object
	 * @return SS_HTTPResponse
	 */
	public function silentPublish($data, $form) {

		$item = $this->record->class;
		$base = ClassInfo::baseDataClass($item);

		// Get the LastEdited value of the currently published version of this page
		$original = Versioned::get_one_by_stage($item, "Live", "\"". $item ."\".\"ID\" = ".$this->record->ID);

		$lastEdited = $original->LastEdited;

		// Invoke existing doPublish
		$response = $this->doPublish($data, $form);
		$publishSucceeded = ($response->class == 'HTMLText');
		
		if ($publishSucceeded) {
			// if the response to doPublish is OK, update LastEdited for the newly published page to it's old value.
			DB::query('UPDATE "'. $base .'_Live" SET "LastEdited" = \'' . $lastEdited . '\' WHERE "ID" = ' . $this->record->ID);
		}

		return $response;
	}

	public function doUnpublish($data, $form) {
		$record = $this->record;

		if($record && !$record->canPublish()) {
			return Security::permissionFailure($this);
		}

		$origStage = Versioned::current_stage();
		Versioned::set_reading_mode('Stage.Live');

		// This way our ID won't be unset
		$clone = clone $record;
		$clone->delete();

		Versioned::set_reading_mode($origStage);

		$message = sprintf(
			'Unpublished %s %s',
			$this->record->singular_name(),
			'"'.Convert::raw2xml($this->record->Title).'"'
		);
		$form->sessionMessage($message, 'good');
		return $this->edit(Controller::curr()->getRequest());
	}
	
	public function doRollback($data, $form) {
		$record = $this->record;

		$record->publish("Live", "Stage", false);
		//$record->writeWithoutVersion();
		$message = "Cancelled Draft changes for \"".Convert::raw2xml($record->Title)."\"";
		
		$form->sessionMessage($message, 'good');
		return $this->edit(Controller::curr()->getRequest());
	}

	public function doDelete($data, $form) {
		$record = $this->record;

		try {
			if (!$record->canDelete()) {
				throw new ValidationException(_t('GridFieldDetailForm.DeletePermissionsFailure',"No delete permissions"),0);
			}
		} catch(ValidationException $e) {
			$form->sessionMessage($e->getResult()->message(), 'bad');
			return Controller::curr()->redirectBack();
		}


		$message = sprintf(
			_t('GridFieldDetailForm.Deleted', 'Deleted %s %s'),
			$this->record->singular_name(),
			'"'.Convert::raw2xml($this->record->Title).'"'
		);
		// due to redirect back this isn't shown until too late.
		//$form->sessionMessage($message, 'good');

		//double check that this deletes all versions
		$clone = clone $record;
		$clone->deleteFromStage("Stage");
		$clone->delete();
		//manually deleting all orphaned _version records
		DB::query("DELETE FROM \"{$this->baseTable()}_versions\" WHERE \"RecordID\" = '{$record->ID}'");
		
		$controller = $this->getToplevelController();
		$controller->getRequest()->addHeader('X-Pjax', 'Content'); // Force a content refresh
		return $controller->redirect($this->getBacklink(), 302); //redirect back to admin section
	}

	/**
	 * Restore the content in the active copy of this SiteTree page to the stage site.
	 * @return SiteTree The SiteTree object.
	 */
	public function doRestoreToStage() {
		$record = $this->record;
		// if no record can be found on draft stage (meaning it has been "deleted from draft" before),
		// create an empty record
		if(!Versioned::get_by_stage($this->baseTable(), 'Stage')->byID($record->ID)) {
			$conn = DB::getConn();
			if(method_exists($conn, 'allowPrimaryKeyEditing')) $conn->allowPrimaryKeyEditing($record->class, true);
			DB::query("INSERT INTO \"{$this->baseTable()}\" (\"ID\") VALUES ($this->ID)");
			if(method_exists($conn, 'allowPrimaryKeyEditing')) $conn->allowPrimaryKeyEditing($record->class, false);
		}
		
		$oldStage = Versioned::current_stage();
		Versioned::reading_stage('Stage');
		$record->forceChange();
		$record->write();
		
		$result = DataObject::get_by_id($this->class, $this->ID);
		
		Versioned::reading_stage($oldStage);
		
		return $result;
	}

	/**
	 * Synonym of {@link doUnpublish}
	 */
	public function doDeleteFromLive() {
		return $this->doUnpublish();
	}

	/**
	 * Compares current draft with live version,
	 * and returns TRUE if no draft version of this page exists,
	 * but the page is still published (after triggering "Delete from draft site" in the CMS).
	 * 
	 * @return boolean
	 */
	public function getIsDeletedFromStage() {
		//if(!$this->record->ID) return true;
		if ($this->isNew()) {
			return false;
		}
		
		$stageVersion = Versioned::get_versionnumber_by_stage($this->record->class, 'Stage', $this->record->ID);

		// Return true for both completely deleted pages and for pages just deleted from stage.
		return !($stageVersion);
	}
	
	/**
	 * Return true if this page exists on the live site
	 */
	public function getExistsOnLive() {
		return (bool)Versioned::get_versionnumber_by_stage($this->record->class, 'Live', $this->record->ID);
	}

	/**
	 * Compares current draft with live version,
	 * and returns TRUE if these versions differ,
	 * meaning there have been unpublished changes to the draft site.
	 * 
	 * @return boolean
	 */
	public function getIsModifiedOnStage() {
		// new unsaved pages could be never be published
		if($this->isNew()) {
			return false;
		}
		
		$stageVersion = Versioned::get_versionnumber_by_stage($this->record->class, 'Stage', $this->record->ID);
		$liveVersion =	Versioned::get_versionnumber_by_stage($this->record->class, 'Live', $this->record->ID);

		return ($stageVersion && $stageVersion != $liveVersion);
	}
	
	/**
	 * Compares current draft with live version,
	 * and returns true if no live version exists,
	 * meaning the page was never published.
	 * 
	 * @return boolean
	 */
	public function getIsAddedToStage() {
		// new unsaved pages could be never be published
		if($this->isNew()) {
			return false;
		}
		
		$stageVersion = Versioned::get_versionnumber_by_stage($this->record->class, 'Stage', $this->record->ID);
		$liveVersion =	Versioned::get_versionnumber_by_stage($this->record->class, 'Live', $this->record->ID);

		return ($stageVersion && !$liveVersion);
	}
	
}
