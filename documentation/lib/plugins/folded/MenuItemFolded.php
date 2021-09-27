<?php

namespace dokuwiki\plugin\folded;

use dokuwiki\Menu\Item\AbstractItem;

/**
 * Class MenuItemFolded
 *
 * Implements the 'fold/unfold all' button for DokuWiki's menu system
 *
 * @package dokuwiki\plugin\folded
 */
class MenuItemFolded extends AbstractItem {

    /** @var string icon file */
    protected $svg = DOKU_INC . 'lib/plugins/folded/menu-folded.svg';

    /**
     * MenuItem constructor.
     */
    public function __construct() {
        parent::__construct();
        global $REV;
        if($REV) $this->params['rev'] = $REV;
    }

    /**
     * Get label from plugin language file
     *
     * @return string
     */
    public function getLabel() {
        $hlp = plugin_load('action', 'folded');
        return $hlp->getLang('fold_unfold_all_button');
    }

    /**
     * Return this item's title
     *
     * @return string
     */
    public function getTitle() {
        return $this->getLabel();
    }

    /**
     * Return the link this item links to
     * 
     * @return string
     */
    public function getLink() {
        return 'javascript:void(0);';
    }

    /**
     * Convenience method to get the attributes for constructing an <a> element
     *
     * @see buildAttributes()
     * @return array
     */
    public function getLinkAttributes($classprefix = 'menuitem ') {
        $attr = array(
            'href' => $this->getLink(),
            'title' => $this->getTitle(),
        );
        $attr['rel'] = 'nofollow';
        $attr['class'] = 'fold_unfold_all_new';
        $attr['onclick'] = 'fold_unfold_all();';

        return $attr;
    }

}
