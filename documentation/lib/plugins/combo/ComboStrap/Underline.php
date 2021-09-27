<?php


namespace ComboStrap;


class Underline
{

    const UNDERLINE_ATTRIBUTE = "underline";
    const CANONICAL = self::UNDERLINE_ATTRIBUTE;

    /**
     * @param TagAttributes $attributes
     */
    public static function processUnderlineAttribute(TagAttributes &$attributes)
    {


        if ($attributes->hasComponentAttribute(Underline::UNDERLINE_ATTRIBUTE)) {
            $value = $attributes->removeComponentAttribute(Underline::UNDERLINE_ATTRIBUTE);
            if (empty($value)){
                $value = true;
            } else {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
            if ($value) {
                $attributes->addClassName("text-decoration-underline");
            } else {
                $attributes->addClassName("text-decoration-none");
            }

        }

    }


}
