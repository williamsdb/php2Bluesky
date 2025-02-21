<?php

class RegexPatterns
{
    const MENTION_REGEX = '/[$|\W](@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)/u';
    const URL_REGEX = '/(https?:\/\/[^\s,)\.]+(?:\.[^\s,)\.]+)*)(?<![\.,:;!?])/i';
    const TAG_REGEX = '/(^|[\\s\\r\\n])[#＃]((?!\\x{fe0f})[^\s\\x{00AD}\\x{2060}\\x{200A}\\x{200B}\\x{200C}\\x{200D}\\x{20e2}]*[^\d\s\p{P}\\x{00AD}\\x{2060}\\x{200A}\\x{200B}\\x{200C}\\x{200D}\\x{20e2}]+[^\s\\x{00AD}\\x{2060}\\x{200A}\\x{200B}\\x{200C}\\x{200D}\\x{20e2}]*)/u';
}
