<?php
class ExhibitPageUpgrader
{
    protected $_db;
    
    protected $_upgraders = array(
        'text' => '_upgradeText',
        'text-image-left' => '_upgradeTextImage',
        'text-image-right' => '_upgradeTextImage',
        'gallery-thumbnails' => '_upgradeGallery',
        'gallery-thumbnails-text-top' => '_upgradeGallery',
        'gallery-thumbnails-text-bottom' => '_upgradeGallery',
        'image-list-left' => '_upgradeImageList',
        'image-list-right' => '_upgradeImageList',
        'image-list-left-thumbs' => '_upgradeImageList',
        'image-list-right-thumbs' => '_upgradeImageList',
        'gallery-full-left' => '_upgradeGalleryFull',
        'gallery-full-right' => '_upgradeGalleryFull'
    );

    public function __construct($db)
    {
        $this->_db = $db;
    }

    public function getDb()
    {
        return $this->_db;
    }

    public function upgradePage($pageId, $pageLayout)
    {
        $db = $this->getDb();

        $sql = <<<SQL
SELECT * FROM `{$db->prefix}exhibit_page_entries`
    WHERE page_id = $pageId
    ORDER BY `order`
SQL;

        $entries = $db->query($sql)->fetchAll();

        if (empty($entries)) {
            // Create no blocks for pages with no content.
            continue;
        }

        if (isset($this->_upgraders[$pageLayout])) {
            $upgrader = $this->_upgraders[$pageLayout];
        } else {
            // The image-list upgrader is the most 
            $upgrader = '_upgradeImageList';
            $pageLayout = 'image-list-left';
        }

        $this->$upgrader($pageId, $entries, $pageLayout);
    }

    protected function _upgradeText($pageId, $entries, $layout)
    {
        $this->_createBlock(array(
            'page_id' => $pageId,
            'layout' => 'text',
            'text' => $entries[0]['text'],
            'order' => 1
        ));
    }

    protected function _upgradeTextImage($pageId, $entries, $layout)
    {
        $db = $this->getDb();

        $filePosition = substr($layout, strrpos($layout, '-') + 1);
        $options = array(
            'file-position' => $filePosition
        );
        $blockId = $this->_createBlock(array(
            'page_id' => $pageId,
            'layout' => 'file-text',
            'text' => $entries[0]['text'],
            'options' => json_encode($options),
            'order' => 1
        ));

        if (!empty($entries[0]['item_id'])) {
            $this->_createAttachment(array(
                'block_id' => $blockId,
                'item_id' => $entries[0]['item_id'],
                'file_id' => $entries[0]['file_id'],
                'caption' => $entries[0]['caption'],
                'order' => 1
            ));
        }
    }

    protected function _upgradeGallery($pageId, $entries, $layout)
    {
        $textTop = false;
        $textBottom = false;
        $nameParts = explode('-', $layout);
        if (count($nameParts) == 4) {
            if ($nameParts[3] == 'top') {
                $textTop = true;
            } else if ($nameParts[3] == 'bottom') {
                $textBottom = true;
            }
        }

        $galleryBlockId = $this->_createBlock(
            array(
                'page_id' => $pageId,
                'layout' => 'gallery',
                'order' => $textTop ? 2 : 1
            )
        );

        $attachmentOrder = 1;
        foreach ($entries as $entry) {
            if (!empty($entry['item_id'])) {
                $this->_createAttachment(array(
                    'block_id' => $galleryBlockId,
                    'item_id' => $entry['item_id'],
                    'file_id' => $entry['file_id'],
                    'caption' => $entry['caption'],
                    'order' => $attachmentOrder++
                ));
            }
        }

        if (($textTop || $textBottom) && !empty($entries[0]['text'])) {
            $this->_createBlock(array(
                'page_id' => $pageId,
                'layout' => 'text',
                'order' => $textTop ? 1 : 2,
                'text' => $entries[0]['text']
            ));
        }
    }

    protected function _upgradeImageList($pageId, $entries, $layout)
    {
        $fileSize = 'fullsize';
        
        $nameParts = explode('-', $layout);
        
        $filePosition = $nameParts[2];
        if (count($nameParts) == 4) {
            $fileSize = 'thumbnail';
        }

        $options = array(
            'file-position' => $filePosition,
            'file-size' => $fileSize
        );
        $encodedOptions = json_encode($options);

        $order = 1;
        foreach ($entries as $entry) {
            // Don't create blocks for empty pairs.
            if (empty($entry['text']) && empty($entry['item_id'])) {
                continue;
            }

            $blockId = $this->_createBlock(array(
                'page_id' => $pageId,
                'layout' => 'file-text',
                'text' => $entry['text'],
                'options' => $encodedOptions,
                'order' => $order++
            ));

            if (!empty($entry['item_id'])) {
                $this->_createAttachment(array(
                    'block_id' => $blockId,
                    'item_id' => $entry['item_id'],
                    'file_id' => $entry['file_id'],
                    'caption' => $entry['caption'],
                    'order' => 1
                ));
            }
        }
    }

    protected function _upgradeGalleryFull($pageId, $entries, $layout)
    {
        $fileSize = 'fullsize';
        
        $nameParts = explode('-', $layout);
        
        $asidePosition = $nameParts[2];

        $options = array(
            'aside-position' => $asidePosition,
            'showcase-file' => '1'
        );

        $galleryBlockId = $this->_createBlock(
            array(
                'page_id' => $pageId,
                'layout' => 'gallery',
                'text' => $entries[0]['text'],
                'options' => json_encode($options),
                'order' => 1
            )
        );

        $order = 1;
        foreach ($entries as $entry) {
            if (!empty($entry['item_id'])) {
                $this->_createAttachment(array(
                    'block_id' => $galleryBlockId,
                    'item_id' => $entry['item_id'],
                    'file_id' => $entry['file_id'],
                    'caption' => $entry['caption'],
                    'order' => $order++
                ));
            }
        }
    }

    protected function _createBlock($data)
    {
        $db = $this->getDb();
        $db->insert('exhibit_page_blocks', $data);
        return $db->lastInsertId();
    }

    protected function _createAttachment($data)
    {
        $db = $this->getDb();
        $db->insert('exhibit_block_attachments', $data);
        return $db->lastInsertId();
    }
}
