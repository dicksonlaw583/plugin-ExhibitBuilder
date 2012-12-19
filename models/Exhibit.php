<?php
/**
 * @copyright Roy Rosenzweig Center for History and New Media, 2007-2012
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package ExhibitBuilder
 */
 
/**
 * Exhibit model.
 *
 * @package ExhibitBuilder
 */
class Exhibit extends Omeka_Record_AbstractRecord implements Zend_Acl_Resource_Interface
{
    public $title;
    public $description;
    public $credits;
    public $featured = 0;
    public $public = 1;
    public $theme;
    public $theme_options;
    public $slug;
    public $added;
    public $modified;
    public $owner_id;

    protected $_related = array('TopPages'=>'getTopPages', 'Tags'=>'getTags');
    
    public function _initializeMixins()
    {
        $this->_mixins[] = new Mixin_Tag($this);
        $this->_mixins[] = new Mixin_Owner($this);
        $this->_mixins[] = new Mixin_Order($this, 'ExhibitPage', 'exhibit_id', 'ExhibitPages');
        $this->_mixins[] = new Mixin_Slug($this, array(
            'slugEmptyErrorMessage' => __('Exhibits must be given a valid slug.'),
            'slugLengthErrorMessage' => __('A slug must be 30 characters or less.'),
            'slugUniqueErrorMessage' => __('Your URL slug is already in use by another exhibit.  Please choose another.')));
        $this->_mixins[] = new Mixin_Timestamp($this);
        $this->_mixins[] = new Mixin_PublicFeatured($this);
        $this->_mixins[] = new Mixin_Search($this);
    }
    
    protected function _validate()
    {
        if (!strlen((string)$this->title)) {
            $this->addError('title', __('An exhibit must be given a title.'));
        }

        if (strlen((string)$this->title) > 255) {
            $this->addError('title', __('The title for an exhibit must be 255 characters or less.'));
        }

        if (strlen((string)$this->theme) > 30) {
            $this->addError('theme', __('The name of your theme must be 30 characters or less.'));
        }
    }

    protected function _delete()
    {
        //get all the pages and delete them
        $pages = $this->getTable('ExhibitPage')->findBy(array('exhibit_id'=>$this->id));
        foreach($pages as $page) {
            $page->delete();
        }
        $this->deleteTaggings();
    }

    protected function afterSave($args)
    {
        if (!$this->public) {
            $this->setSearchTextPrivate();
        }
        $this->setSearchTextTitle($this->title);
        $this->addSearchText($this->title);
        $this->addSearchText($this->description);
        
        if ($args['post']) {
            //Add the tags after the form has been saved
            $post = $args['post'];
            $this->applyTagString($post['tags']);
            $pages = $post['pages'];
            $this->savePagesParentOrder(null, $pages);
        }
        

    }

    /**
     * Updates page parents and orders
     */
    private function savePagesParentOrder($parentId, $pages)
    {
         foreach($pages as $pageId => $pageInfo) {
             $rawPageOrdersByPageId[$pageId] = $pageInfo['order'];
         }

         asort($rawPageOrdersByPageId, SORT_NUMERIC);
         $pageOrder = 0;
         $pageOrdersByPageId = array();
         foreach($rawPageOrdersByPageId as $pageId => $rawPageOrder) {
             $pageOrder++;
             $pageOrdersByPageId[$pageId] = $pageOrder;
         }

            // Save the new page orders
         foreach($pageOrdersByPageId as $pageId => $pageOrder) {
             $exhibitPage = $this->getDb()->getTable('ExhibitPage')->find($pageId);
             //@TODO: figure out null for top level, but also work with arbitrary levels of nesting later
             $exhibitPage->parent_id = $parentId; // Change the parent page if necessary
             $exhibitPage->order = $pageOrdersByPageId[$pageId]; // Change the page order
             $exhibitPage->save();
         }
    }

    public function getTopPages()
    {
        if (!$this->exists()) {
            return array();
        }

        return $this->getTable('ExhibitPage')->findBy(array('exhibit'=>$this->id, 'topOnly'=>true, 'sort_field'=>'order'));
    }

    public function countTopPages()
    {
        if (!$this->exists()) {
            return 0;
        }

        return $this->getTable('ExhibitPage')->count(array('exhibit'=>$this->id, 'topOnly'=>true));
    }


    public function getTopPageBySlug($slug)
    {

    }

    public function getFirstTopPage()
    {

    }


    public function getPagesCount($topOnly = true)
    {
        return $this->getTable('ExhibitPage')->count(array('exhibit'=>$this->id, 'topOnly'=>$topOnly));
    }

    /**
     * Determine whether an exhibit uses a particular item on any of its pages.
     *
     * @param Item $item
     * @return boolean
     */
    public function hasItem(Item $item)
    {
        if (!$item->exists()) {
           throw new InvalidArgumentException("Item does not exist (is not persisted).");
        }
        if (!$this->exists()) {
           throw new RuntimeException("Cannot call hasItem() on a new (non-persisted) exhibit.");
        }
        return $this->getTable()->exhibitHasItem($this->id, $item->id);
    }

    public function setThemeOptions($themeOptions, $themeName = null)
    {
        if ($themeName === null) {
            $themeName = $this->theme;
        }
        if ($themeName !== null && $themeName != '') {
            $themeOptionsArray = unserialize($this->theme_options);
            $themeOptionsArray[$themeName] = $themeOptions;
        }

        $this->theme_options = serialize($themeOptionsArray);
    }

    public function getThemeOptions($themeName = null)
    {
        if ($themeName === null) {
            $themeName = $this->theme;
        }

        $themeName = (string)$themeName;
        if ($themeName == '' || empty($this->theme_options)) {
            return array();
        }

        $themeOptionsArray = unserialize($this->theme_options);
        return @$themeOptionsArray[$themeName];
    }
    
    public function getRecordUrl($action = 'show')
    {
        $urlHelper = new Omeka_View_Helper_Url;

        if ('show' == $action) {
            $params = array('slug' => $this->slug);
            return $urlHelper->url($params, 'exhibitSimple');
        }
        $params = array('action' => $action, 'id' => $this->id);
        return $urlHelper->url($params, 'exhibitStandard');
    }

    /**
     * Required by Zend_Acl_Resource_Interface.
     *
     * @return string
     */
    public function getResourceId()
    {
        return 'ExhibitBuilder_Exhibits';
    }
}
