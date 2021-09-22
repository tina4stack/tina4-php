<?php


namespace ComboStrap;

/**
 * Class Skin
 * @package ComboStrap
 * Processing the skin attribute
 */
class Skin
{

    const CANONICAL = "skin";


    static $colors = array(
        "info" => array(
            ColorUtility::COLOR => "#0c5460",
            Background::BACKGROUND_COLOR => "#d1ecf1",
            ColorUtility::BORDER_COLOR => "#bee5eb"
        ),
        "tip" => array(
            ColorUtility::COLOR => "#6c6400",
            Background::BACKGROUND_COLOR => "#fff79f",
            ColorUtility::BORDER_COLOR => "#FFF78c"
        ),
        "warning" => array(
            ColorUtility::COLOR => "#856404",
            Background::BACKGROUND_COLOR => "#fff3cd",
            ColorUtility::BORDER_COLOR => "#ffeeba"
        ),
        "primary" => array(
            ColorUtility::COLOR => "#fff",
            Background::BACKGROUND_COLOR => "#007bff",
            ColorUtility::BORDER_COLOR => "#007bff"
        ),
        "secondary" => array(
            ColorUtility::COLOR => "#fff",
            Background::BACKGROUND_COLOR => "#6c757d",
            ColorUtility::BORDER_COLOR => "#6c757d"
        ),
        "success" => array(
            ColorUtility::COLOR => "#fff",
            Background::BACKGROUND_COLOR => "#28a745",
            ColorUtility::BORDER_COLOR => "#28a745"
        ),
        "danger" => array(
            ColorUtility::COLOR => "#fff",
            Background::BACKGROUND_COLOR => "#dc3545",
            ColorUtility::BORDER_COLOR => "#dc3545"
        ),
        "dark" => array(
            ColorUtility::COLOR => "#fff",
            Background::BACKGROUND_COLOR => "#343a40",
            ColorUtility::BORDER_COLOR => "#343a40"
        ),
        "light" => array(
            ColorUtility::COLOR => "#fff",
            Background::BACKGROUND_COLOR => "#f8f9fa",
            ColorUtility::BORDER_COLOR => "#f8f9fa"
        )
    );

    public static function processSkinAttribute(TagAttributes &$attributes)
    {
        // Skin
        $skinAttributes = "skin";
        if ($attributes->hasComponentAttribute($skinAttributes)) {
            $skinValue = $attributes->getValueAndRemove($skinAttributes);
            if (!$attributes->hasComponentAttribute(TagAttributes::TYPE_KEY)) {
                LogUtility::msg("A component type is mandatory when using the skin attribute", LogUtility::LVL_MSG_WARNING, self::CANONICAL);

            } else {
                $type = $attributes->getValue(TagAttributes::TYPE_KEY);
                if (!isset(self::$colors[$type])) {
                    $types = implode(", ", array_keys(self::$colors));
                    LogUtility::msg("The type value ($type) is not supported. Only the following types value may be used: $types", LogUtility::LVL_MSG_WARNING, self::CANONICAL);
                } else {
                    $color = self::$colors[$type];
                    switch ($skinValue) {
                        case "contained":
                            $attributes->addStyleDeclaration(ColorUtility::COLOR, $color[ColorUtility::COLOR]);
                            $attributes->addStyleDeclaration(Background::BACKGROUND_COLOR, $color[Background::BACKGROUND_COLOR]);
                            $attributes->addStyleDeclaration(ColorUtility::BORDER_COLOR, $color[ColorUtility::BORDER_COLOR]);
                            Shadow::addMediumElevation($attributes);
                            break;
                        case "filled":
                        case "solid":
                            $attributes->addStyleDeclaration(ColorUtility::COLOR, $color[ColorUtility::COLOR]);
                            $attributes->addStyleDeclaration(Background::BACKGROUND_COLOR, $color[Background::BACKGROUND_COLOR]);
                            $attributes->addStyleDeclaration(ColorUtility::BORDER_COLOR, $color[ColorUtility::BORDER_COLOR]);
                            break;
                        case "outline":
                            $primaryColor = $color[ColorUtility::COLOR];
                            if ($primaryColor === "#fff") {
                                $primaryColor = $color[Background::BACKGROUND_COLOR];
                            }
                            $attributes->addStyleDeclaration(ColorUtility::COLOR, $primaryColor);
                            $attributes->addStyleDeclaration(Background::BACKGROUND_COLOR, "transparent");
                            $borderColor = $color[Background::BACKGROUND_COLOR];
                            if ($attributes->hasStyleDeclaration(ColorUtility::BORDER_COLOR)) {
                                // Color in the `border` attribute
                                // takes precedence in the `border-color` if located afterwards
                                // We don't take the risk
                                $borderColor = $attributes->getAndRemoveStyleDeclaration(ColorUtility::BORDER_COLOR);
                            }
                            $attributes->addStyleDeclaration("border", "1px solid " . $borderColor);

                            break;
                        case "text":
                            $primaryColor = $color[ColorUtility::COLOR];
                            if ($primaryColor === "#fff") {
                                $primaryColor = $color[Background::BACKGROUND_COLOR];
                            }
                            $attributes->addStyleDeclaration(ColorUtility::COLOR, $primaryColor);
                            $attributes->addStyleDeclaration(Background::BACKGROUND_COLOR, "transparent");
                            $attributes->addStyleDeclaration(ColorUtility::BORDER_COLOR, "transparent");
                            break;
                    }
                }
            }
        }
    }

}
