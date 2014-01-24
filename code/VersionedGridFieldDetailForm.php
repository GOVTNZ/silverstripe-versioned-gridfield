<?php

/**
 * VersionedGridFieldDetailForm & VersionedGridFieldDetailForm_ItemRequest
 * Allows managing versioned objects through gridfield.
 * See README for details 
 */
class VersionedGridFieldDetailForm extends GridFieldDetailForm {
	
}

class VersionedGridFieldDetailForm_ItemRequest extends GridFieldDetailForm_ItemRequest {

	private static $allowed_actions = array(
		'edit',
		'view',
		'ItemEditForm'
	);

	/**
	 * Check if this page has been saved before
	 *
	 * @return bool True if this page is new
	 */
	public function isNew() {
		if (empty($this->record->ID)) {
			return true;
		}

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

		$result = DB::query('SELECT "ID" FROM "' . $this->baseTable() . '_Live" WHERE "ID" = ' . $this->record->ID);

		return !is_null($result->value());
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
		return (in_array('CMSPreviewable', class_implements($this->record)) && !$this->isNew());
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
				FormAction::create('doUnpublish', _t('SiteTree.BUTTONUNPUBLISH', 'Unpublish'), 'delete')
					->setUseButtonTag(true)->setDescription("Remove this {$classname} from the published site")
					->addExtraClass('ss-ui-action-destructive')->setAttribute('data-icon', 'unpublish')
			);
		}

		if ($this->stagesDiffer('Stage', 'Live') && !$this->IsDeletedFromStage) {
			if($this->isPublished() && $this->canEdit())	{
				// "rollback"
				$minorActions->push(
					FormAction::create('doRollback', 'Cancel draft changes', 'delete')
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
		// ensure link method is defined & non-null before allowing preview
		if ($this->canPreview() && method_exists($this->record, 'Link') && $this->record->Link()) {
			$actions->push(
				LiteralField::create("preview",
					sprintf("<a href=\"%s\" class=\"ss-ui-button\" data-icon=\"preview\" target=\"_blank\">%s &raquo;</a>",
						$this->record->Link()."?stage=Stage",
						_t('LeftAndMain.PreviewButton', 'Preview')
					)
				)
			);
		}
		
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
		$record->publish("Stage", "Live");

		$message = sprintf(
			_t('GridFieldDetailForm.Published', 'Published %s %s'),
			$this->record->singular_name(),
			'"' . htmlspecialchars($this->record->Title, ENT_QUOTES) . '"'
		);
		
		$form->sessionMessage($message, 'good');
		return $this->edit(Controller::curr()->getRequest());
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
			'"' . htmlspecialchars($this->record->Title, ENT_QUOTES) . '"'
		);
		$form->sessionMessage($message, 'good');
		return $this->edit(Controller::curr()->getRequest());
	}
	
	function doRollback($data, $form) {
		$record = $this->record;

		$record->publish("Live", "Stage", false);

		$message = "Cancelled Draft changes for \"" . htmlspecialchars($record->Title, ENT_QUOTES) . "\"</a>";
		
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
			'"' . htmlspecialchars($this->record->Title, ENT_QUOTES) . '"'
		);

		$form->sessionMessage($message, 'good');

		$controller = Controller::curr();
		$noActionURL = $controller->removeAction($data['url']);
		$controller->getRequest()->addHeader('X-Pjax', 'Content'); // Force a content refresh
		//double check that this deletes all versions

		$clone = clone $record;
		$clone->deleteFromStage("Stage");
		$clone->delete();
		//manually deleting all orphaned _version records
		DB::query("DELETE FROM \"{$this->baseTable()}_versions\" WHERE \"RecordID\" = '{$record->ID}'");
		return $controller->redirect($this->getBackLink(), 302); // redirect back
	}

	/**
	 * Restore the content in the active copy of this SiteTree page to the stage site.
	 * @return SiteTree The SiteTree object.
	 */
	public function doRestoreToStage() {
		$record = $this->record;
		// if no record can be found on draft stage (meaning it has been "deleted from draft" before),
		// create an empty record
		if(!DB::query("SELECT \"ID\" FROM \"{$this->baseTable()}\" WHERE \"ID\" = $record->ID")->value()) {
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
