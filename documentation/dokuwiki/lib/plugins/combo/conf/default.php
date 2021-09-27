<?php
/**
 *
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * The config manager is parsing this fucking file because they want
 * to be able to use 60*60*24 ???? :(
 *
 * See {@link \dokuwiki\plugin\config\core\ConfigParser::parse()}
 *
 * Which means that only value can be given as:
 *   * key
 *   * and value
 * The test test_plugin_default in plugin.test.php is checking that
 *
 * What fuck up is fucked up.
 *
 * The solution:
 *   * The literal value is copied
 *   * A link to the constant is placed before
 */


use ComboStrap\Icon;
use ComboStrap\Prism;


/**
 * Related UI components
 */
$conf['maxLinks'] = 10;
$conf['extra_pattern'] = '{{backlinks>.}}';

/**
 * Disqus
 * See {@link syntax_plugin_combo_disqus::CONF_DEFAULT_ATTRIBUTES}
 */
$conf['disqusDefaultAttributes'] = 'shortName=""';

/**
 * Enable ie {@link action_plugin_combo_urlmanager::URL_MANAGER_ENABLE_CONF}
 */
$conf['enableUrlManager']=1;
/**
 * ie {@link action_plugin_combo_urlmanager::GO_TO_BEST_END_PAGE_NAME}
 */
$conf['ActionReaderFirst'] = 'GoToBestEndPageName';

/**
 * ie {@link action_plugin_combo_urlmanager::GO_TO_BEST_PAGE_NAME}
 */
$conf['ActionReaderSecond'] = 'GoToBestPageName';
/**
 * ie {@link action_plugin_combo_urlmanager::GO_TO_SEARCH_ENGINE}
 */
$conf['ActionReaderThird'] = 'GoToSearchEngine';
$conf['GoToEditMode'] = 1;
$conf['ShowPageNameIsNotUnique'] = 1;
$conf['ShowMessageClassic'] = 1;
$conf['WeightFactorForSamePageName'] = 4;
$conf['WeightFactorForStartPage'] = 3;
$conf['WeightFactorForSameNamespace'] = 5;

/**
 * See {@link UrlManagerBestEndPage::CONF_MINIMAL_SCORE_FOR_REDIRECT_DEFAULT}
 */
$conf['BestEndPageMinimalScoreForIdRedirect'] = 0;

/**
 * Does automatic canonical processing is on
 */
$conf['MinimalNamesCountForAutomaticCanonical'] = 0;

/**
 * Icon Namespace
 * See {@link Icon::CONF_ICONS_MEDIA_NAMESPACE}
 * See {@link Icon::CONF_ICONS_MEDIA_NAMESPACE_DEFAULT}
 */
$conf['icons_namespace'] = ":combostrap:icons";

/**
 * Default library
 * See {@link Icon::CONF_DEFAULT_ICON_LIBRARYE}
 */
$conf['defaultIconLibrary'] = "md";

/**
 * Css Optimization
 * See {@link action_plugin_combo_css::CONF_ENABLE_MINIMAL_FRONTEND_STYLESHEET}
 * See {@link action_plugin_combo_css::CONF_DISABLE_DOKUWIKI_STYLESHEET}
 */
$conf['enableMinimalFrontEndStylesheet'] = 0;
$conf['disableDokuwikiStylesheet'] = 0;

/**
 * Metadata Viewer
 * See {@link \ComboStrap\MetadataUtility::CONF_ENABLE_WHEN_EDITING
 * See {@link \ComboStrap\MetadataUtility::CONF_METADATA_DEFAULT_ATTRIBUTES
 * See {@link \ComboStrap\MetadataUtility::EXCLUDE_ATTRIBUTE
 */
$conf['enableMetadataViewerWhenEditing'] = 1;
$conf['metadataViewerDefaultAttributes'] = 'title="Metadata" exclude="tableofcontents"';

/**
 * Badge
 * See {@link syntax_plugin_combo_badge::CONF_DEFAULT_ATTRIBUTES_KEY
 */
$conf['defaultBadgeAttributes'] = 'type="info" rounded="true"';

/**
 * Ads
 * See {@link \ComboStrap\AdsUtility::CONF_IN_ARTICLE_PLACEHOLDER
 */
$conf['AdsInArticleShowPlaceholder'] = 0;

/**
 * Code
 * See {@link syntax_plugin_combo_code::CONF_CODE_ENABLE}
 * {@link Prism::CONF_PRISM_THEME}
 */
$conf['codeEnable'] = 1;
$conf['fileEnable'] = 1;
$conf['prismTheme'] = "tomorrow";
$conf['bashPrompt'] = "#";
$conf['batchPrompt'] = 'C:\\';
$conf['powershellPrompt'] = 'PS C:\\';

/**
 * Low Quality Page Protection
 * See {@link \ComboStrap\LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_ENABLE}
 * See {@link \ComboStrap\LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_MODE}
 * See {@link \ComboStrap\LowQualityPage::CONF_LOW_QUALITY_PAGE_LINK_TYPE}
 */
$conf['lowQualityPageProtectionEnable'] = 0;
$conf['lowQualityPageProtectionMode'] = "robot";
$conf['lowQualityPageLinkType'] = "normal";


/**
 * Preformatted mode disable
 * See {@link syntax_plugin_combo_preformatted::CONF_PREFORMATTED_ENABLE}
 * See {@link syntax_plugin_combo_preformatted::CONF_PREFORMATTED_EMPTY_CONTENT_NOT_PRINTED_ENABLE}
 */
$conf['preformattedEnable'] = 1;
$conf['preformattedEmptyContentNotPrintedEnable'] = 1;

/**
 * {@link renderer_plugin_combo_analytics::CONF_MANDATORY_QUALITY_RULES}
 */
$conf['mandatoryQualityRules'] = 'words_min,internal_backlinks_min,internal_links_min';

/**
 * {@link action_plugin_combo_autofrontmatter::CONF_AUTOFRONTMATTER_ENABLE}
 */
$conf['autoFrontMatterEnable'] = 1;

/**
 * {@link action_plugin_combo_qualitymessage::CONF_EXCLUDED_QUALITY_RULES_FROM_DYNAMIC_MONITORING}
 * {@link action_plugin_combo_qualitymessage::CONF_DISABLE_QUALITY_MONITORING}
 */
$conf['excludedQualityRulesFromDynamicMonitoring'] = 'words_by_section_avg_min,words_by_section_avg_max';
$conf['disableDynamicQualityMonitoring'] = 0;

/**
 * Link
 * Class in link {@link \ComboStrap\LinkUtility::CONF_USE_DOKUWIKI_CLASS_NAME}
 * Preview on link {@link \ComboStrap\LinkUtility::CONF_PREVIEW_LINK}
 * Enable {@link syntax_plugin_combo_link::CONF_DISABLE_LINK}
 */
$conf['useDokuwikiLinkClassName'] = 0;
$conf['disableLink'] = 0;
$conf['previewLink'] = 0;

/**
 * Twitter
 * {@link action_plugin_combo_metatwitter::CONF_DEFAULT_TWITTER_IMAGE}
 */
$conf['defaultTwitterImage'] = ":apple-touch-icon.png";
$conf['twitterSiteHandle'] = "";
$conf['twitterSiteId'] = "";
$conf['twitter:dnt'] = "on";
$conf['twitter:widgets:theme'] = "light";
$conf['twitter:widgets:border-color'] = "#55acee";

/**
 * Page Image {@link Page::CONF_DISABLE_FIRST_IMAGE_AS_PAGE_IMAGE}
 */
$conf['disableFirstImageAsPageImage'] = 0;

/**
 * Facebook
 * {@link action_plugin_combo_metafacebook::CONF_DEFAULT_FACEBOOK_IMAGE}
 */
$conf['defaultFacebookImage'] = ":logo-facebook.png";

/**
 * Country
 * {@link Site::CONF_SITE_ISO_COUNTRY}
 */
$conf['siteIsoCountry'] = "";

/**
 *
 * See {@link \ComboStrap\Publication::CONF_LATE_PUBLICATION_PROTECTION_ENABLE}
 * See {@link \ComboStrap\Publication::CONF_LATE_PUBLICATION_PROTECTION_MODE}
 */
$conf['latePublicationProtectionEnable'] = 1;
$conf["latePublicationProtectionMode"] = "acl";

/**
 * Default page type
 * {@link Page::CONF_DEFAULT_PAGE_TYPE}
 */
$conf["defaultPageType"] = "article";

/**
 * Default shadow elevation
 * {@link \ComboStrap\Shadow::CONF_DEFAULT_VALUE}
 */
$conf["defaultShadowLevel"] = "medium";


/**
 * Lazy loading {@link \ComboStrap\SvgImageLink::CONF_LAZY_LOAD_ENABLE}
 */
$conf["svgLazyLoadEnable"] = 1;

/**
 * Lazy loading {@link \ComboStrap\SvgImageLink::CONF_SVG_INJECTION_ENABLE}
 */
$conf["svgInjectionEnable"] = 1;

/**
 * Svg Optimization Disable {@link \ComboStrap\SvgDocument::CONF_SVG_OPTIMIZATION_ENABLE}
 */
$conf["svgOptimizationEnable"] = 1;

/**
 * Svg Inline Max size {@link \ComboStrap\SvgImageLink::CONF_MAX_KB_SIZE_FOR_INLINE_SVG}
 */
$conf["svgMaxInlineSizeKb"] = 2;

/**
 * The name of the group of user that can upload svg
 * {@link action_plugin_combo_svg::CONF_SVG_UPLOAD_GROUP_NAME}
 */
$conf["svgUploadGroupName"] = "";

/**
 * Svg Optimization
 * {@link \ComboStrap\SvgDocument::CONF_OPTIMIZATION_NAMESPACES_TO_KEEP}
 * The attribute to delete separates by a ,
 */
$conf["svgOptimizationNamespacesToKeep"] = "";

/**
 * Svg Optimization
 * {@link \ComboStrap\SvgDocument::CONF_OPTIMIZATION_ATTRIBUTES_TO_DELETE}
 * The attribute to delete separates by a ,
 */
$conf["svgOptimizationAttributesToDelete"] = "id, style";
/**
 * {@link \ComboStrap\SvgDocument::CONF_OPTIMIZATION_ELEMENTS_TO_DELETE}
 */
$conf["svgOptimizationElementsToDelete"] = "script, style, title, desc";
/**
 * {@link \ComboStrap\SvgDocument::CONF_OPTIMIZATION_ELEMENTS_TO_DELETE_IF_EMPTY}
 */
$conf["svgOptimizationElementsToDeleteIfEmpty"] = "metadata, defs";

/**
 * {@link \ComboStrap\SvgDocument::CONF_PRESERVE_ASPECT_RATIO_DEFAULT}
 */

$conf["svgPreserveAspectRatioDefault"] = "xMidYMid slice";

/**
 * Lazy loading {@link \ComboStrap\RasterImageLink::CONF_LAZY_LOADING_ENABLE}
 */
$conf["rasterImageLazyLoadingEnable"] = 1;

/**
 * {@link \ComboStrap\RasterImageLink::CONF_RESPONSIVE_IMAGE_MARGIN}
 */
$conf["responsiveImageMargin"] = "20px";

/**
 * {@link \ComboStrap\RasterImageLink::CONF_RETINA_SUPPORT_ENABLED}
 */
$conf["retinaRasterImageEnable"] = 0;

/**
 * {@link \ComboStrap\LazyLoad::CONF_LAZY_LOADING_PLACEHOLDER_COLOR
 */
$conf["lazyLoadingPlaceholderColor"] = "#cbf1ea";


/**
 * {@link \ComboStrap\MediaLink::CONF_IMAGE_ENABLE}
 */
$conf["imageEnable"] = 1;

/**
 * Default linking value
 * {@link \ComboStrap\MediaLink::CONF_DEFAULT_LINKING}
 */
$conf["defaultImageLinking"] = "direct";

/**
 * Float
 *  {@link \ComboStrap\FloatAttribute::CONF_FLOAT_DEFAULT_BREAKPOINT}
 */
$conf["floatDefaultBreakpoint"] = "sm";

/**
 * Outline Numbering
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_ENABLE}
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL2}
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL3}
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL4}
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL5}
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL6}
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_COUNTER_SEPARATOR}
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_PREFIX}
 * {@link action_plugin_combo_outlinenumbering::CONF_OUTLINE_NUMBERING_SUFFIX}
 */
$conf["outlineNumberingEnable"] = 0;
$conf["outlineNumberingCounterStyleLevel2"] = "decimal";
$conf["outlineNumberingCounterStyleLevel3"] = "decimal";
$conf["outlineNumberingCounterStyleLevel4"] = "decimal";
$conf["outlineNumberingCounterStyleLevel5"] = "decimal";
$conf["outlineNumberingCounterStyleLevel6"] = "decimal";
$conf["outlineNumberingCounterSeparator"] = ".";
$conf["outlineNumberingPrefix"] = "";
$conf["outlineNumberingSuffix"] = " - ";

/**
 * Form
 * {@link \ComboStrap\Identity::CONF_ENABLE_LOGO_ON_IDENTITY_FORMS}
 * {@link action_plugin_combo_login::CONF_ENABLE_LOGIN_FORM }
 * {@link action_plugin_combo_registration::CONF_ENABLE_REGISTER_FORM }
 * {@link action_plugin_combo_resend::CONF_ENABLE_RESEND_PWD_FORM }
 * {@link action_plugin_combo_profile::CONF_ENABLE_PROFILE_UPDATE_FORM }
 * {@link action_plugin_combo_profile::CONF_ENABLE_PROFILE_DELETE_FORM }
 */
$conf["enableLogoOnIdentityForms"] = 1;
$conf["enableLoginForm"] = 1;
$conf["enableRegistrationForm"] = 1;
$conf["enableResendPwdForm"] = 1;
$conf["enableProfileUpdateForm"] = 1;
$conf["enableProfileDeleteForm"] = 1;

/**
 * {@link syntax_plugin_combo_comment::CONF_OUTPUT_COMMENT}
 */
$conf['outputComment'] = 0;

