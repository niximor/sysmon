<?php

class EmailValidationError extends Exception {
    const ERR_MISSING_OK_PART = 1;
    const ERR_MISSING_DOMAIN_PART = 2;
    const ERR_TOO_LONG = 3;
}

class Email {
    public static function validate($email) {
        $email = str_replace("\t", " ", $email);
        $email = str_replace("\r", " ", $email);
        $email = str_replace("\n", " ", $email);
        $email = trim($email);

        // Reverse parse out the initial domain/IP address part of the e-mail address.
        $domain = "";
        $state = "domend";
        $cfwsdepth = 0;
        while ($email != "" && $state != "") {
            $prevchr = substr($email, -2, 1);
            $lastchr = substr($email, -1);

            switch ($state) {
                case "domend":
                    if ($lastchr == ")") {
                        $laststate = "domain";
                        $state = "cfws";
                    } elseif ($lastchr == "]" || $lastchr == "}") {
                        $domain .= "]";
                        $email = trim(substr($email, 0, -1));
                        $state = "ipaddr";
                    } else {
                        $state = "domain";
                    }
                    break;

                case "cfws":
                    if ($prevchr == "\\") {
                        $email = trim(substr($email, 0, -2));
                    } elseif ($lastchr == ")") {
                        $email = trim(substr($email, 0, -1));
                        $depth++;
                    } elseif ($lastchr == "(") {
                        $email = trim(substr($email, 0, -1));
                        $depth--;
                        if (!$depth && substr($email, -1) != ")") {
                            $state = $laststate;
                        }
                    } else {
                        $email = trim(substr($email, 0, -1));
                    }
                    break;

                case "ipaddr":
                    if ($lastchr == "[" || $lastchr == "{" || $lastchr == "@") {
                        $domain .= "[";
                        $state = "@";

                        if ($lastchr == "@") {
                            break;
                        }
                    } elseif ($lastchr == "," || $lastchr == ".") {
                        $domain .= ".";
                    } elseif ($lastchr == ";" || $lastchr == ":") {
                        $domain .= ":";
                    } elseif (preg_match('/[A-Za-z0-9]/', $lastchr)) {
                        $domain .= $lastchr;
                    }

                    $email = trim(substr($email, 0, -1));

                    break;

                case "domain":
                    if ($lastchr == "@") {
                        $state = "@";

                        break;
                    } elseif ($lastchr == ")") {
                        $state = "cfws";
                        $laststate = "@";

                        break;
                    } elseif ($lastchr == "," || $lastchr == ".") {
                        $domain .= ".";
                    } elseif (preg_match('/[A-Za-z0-9-]/', $lastchr)) {
                        $domain .= $lastchr;
                    }

                    $email = trim(substr($email, 0, -1));

                    break;

                case "@":
                    if ($lastchr == "@") {
                        $state = "";
                    }

                    $email = trim(substr($email, 0, -1));

                    break;
            }
        }

        $domain = strrev($domain);
        $parts = explode(".", $domain);

        foreach ($parts as $num => $part) {
            $parts[$num] = str_replace(" ", "-", trim(str_replace("-", " ", $part)));
        }

        $domain = implode(".", $parts);

        // Forward parse out the local part of the e-mail address.
        // Remove CFWS (comments, folding whitespace).
        while (substr($email, 0, 1) == "(") {
            while ($email != "") {
                $currchr = substr($email, 0, 1);
                if ($currchr == "\\") {
                    $email = trim(substr($email, 2));
                } elseif ($currchr == "(") {
                    $depth++;
                    $email = trim(substr($email, 1));
                } elseif ($currchr == ")") {
                    $email = trim(substr($email, 1));
                    $depth--;
                    if (!$depth && substr($email, 0, 1) != "(") {
                        break;
                    }
                }
            }
        }

        // Process quoted/unquoted string.
        $local = "";
        if (substr($email, 0, 1) == "\"") {
            $email = substr($email, 1);
            while ($email != "") {
                $currchr = substr($email, 0, 1);
                $nextchr = substr($email, 1, 1);

                if ($currchr == "\\") {
                    if ($nextchr == "\\" || $nextchr == "\"") {
                        $local .= substr($email, 0, 2);
                        $email = substr($email, 2);
                    } elseif (ord($nextchr) >= 33 && ord($nextchr) <= 126) {
                        $local .= substr($email, 1, 1);
                        $email = substr($email, 2);
                    }
                } elseif ($currchr == "\"") {
                    break;
                } elseif (ord($currchr) >= 33 && ord($nextchr) <= 126) {
                    $local .= substr($email, 0, 1);
                    $email = substr($email, 1);
                } else {
                    $email = substr($email, 1);
                }
            }

            if (substr($local, -1) != "\"") {
                $local .= "\"";
            }
        } else {
            while ($email != "") {
                $currchr = substr($email, 0, 1);

                if (preg_match("/[A-Za-z0-9!#\$%&'*+\\/=?^_`{|}~.-]/", $currchr)) {
                    $local .= $currchr;
                    $email = substr($email, 1);
                } else {
                    break;
                }
            }

            $local = preg_replace('/[.]+/', ".", $local);

            if (substr($local, 0, 1) == ".") {
                $local = substr($local, 1);
            }

            if (substr($local, -1) == ".") {
                $local = substr($local, 0, -1);
            }
        }

        while (substr($local, -2) == "\\\"") {
            $local = substr($local, 0, -2)."\"";
        }

        if ($local == "\"" || $local == "\"\"") {
            $local = "";
        }

        // Analyze the domain/IP part and fix any issues.
        $domain = preg_replace('/[.]+/', ".", $domain);
        if (substr($domain, -1) == "]") {
            if (substr($domain, 0, 1) != "[") {
                $domain = "[" . $domain;
            }

            // Process the IP address.
            if (strtolower(substr($domain, 0, 6)) == "[ipv6:") {
                $ipaddr = self::NormalizeIP(substr($domain, 6, -1));
            } else {
                $ipaddr = self::NormalizeIP(substr($domain, 1, -1));
            }

            if ($ipaddr["ipv4"] != "") {
                $domain = "[" . $ipaddr["ipv4"] . "]";
            } else {
                $domain = "[IPv6:" . $ipaddr["ipv6"] . "]";
            }
        } else {
            // Process the domain.
            if (substr($domain, 0, 1) == ".") {
                $domain = substr($domain, 1);
            }

            if (substr($domain, -1) == ".") {
                $domain = substr($domain, 0, -1);
            }

            $domain = explode(".", $domain);

            foreach ($domain as $num => $part) {
                if (substr($part, 0, 1) == "-") {
                    $part = substr($part, 1);
                }

                if (substr($part, -1) == "-") {
                    $part = substr($part, 0, -1);
                }

                if (strlen($part) > 63) {
                    $part = substr($part, 0, 63);
                }

                $domain[$num] = $part;
            }

            $domain = implode(".", $domain);
        }

        // Validate the final lengths.
        $y = strlen($local);
        $y2 = strlen($domain);

        $email = $local."@".$domain;
        if (!$y) {
            throw new EmailValidationError("Missing local part.", EmailValidationError::ERR_MISSING_LOCAL_PART);
        }

        if (!$y2) {
            throw new EmailValidationError("Missing domain part.", EmailValidationError::ERR_MISSING_DOMAIN_PART);
        }

        if ($y > 64 || $y2 > 253 || $y + $y2 + 1 > 253) {
            throw new EmailValidationError("Address is too long.", EmailValidationError::ERR_TOO_LONG);
        }

        return $email;
    }

    public static function NormalizeIP($ipaddr) {
        $ipv4addr = "";
        $ipv6addr = "";

        // Generate IPv6 address.
        $ipaddr = strtolower(trim($ipaddr));
        if (strpos($ipaddr, ":") === false) {
            $ipaddr = "::ffff:" . $ipaddr;
        }

        $ipaddr = explode(":", $ipaddr);

        if (count($ipaddr) < 3) {
            $ipaddr = array("", "", "0");
        }

        $ipaddr2 = array();
        $foundpos = false;

        foreach ($ipaddr as $num => $segment) {
            $segment = trim($segment);
            if ($segment != "") {
                $ipaddr2[] = $segment;
            } elseif ($foundpos === false && count($ipaddr) > $num + 1 && $ipaddr[$num + 1] != "") {
                $foundpos = count($ipaddr2);
                $ipaddr2[] = "0000";
            }
        }

        // Convert ::ffff:123.123.123.123 format.
        if (strpos($ipaddr2[count($ipaddr2) - 1], ".") !== false) {
            $x = count($ipaddr2) - 1;
            if ($ipaddr2[count($ipaddr2) - 2] != "ffff") {
                $ipaddr2[$x] = "0";
            } else {
                $ipaddr = explode(".", $ipaddr2[$x]);
                if (count($ipaddr) != 4) {
                    $ipaddr2[$x] = "0";
                } else {
                    $ipaddr2[$x] = str_pad(strtolower(dechex($ipaddr[0])), 2, "0", STR_PAD_LEFT).str_pad(strtolower(dechex($ipaddr[1])), 2, "0", STR_PAD_LEFT);
                    $ipaddr2[] = str_pad(strtolower(dechex($ipaddr[2])), 2, "0", STR_PAD_LEFT).str_pad(strtolower(dechex($ipaddr[3])), 2, "0", STR_PAD_LEFT);
                }
            }
        }

        $ipaddr = array_slice($ipaddr2, 0, 8);

        if ($foundpos !== false && count($ipaddr) < 8) {
            array_splice($ipaddr, $foundpos, 0, array_fill(0, 8 - count($ipaddr), "0000"));
        }

        foreach ($ipaddr as $num => $segment) {
            $ipaddr[$num] = substr(str_pad(strtolower(dechex(hexdec($segment))), 4, "0", STR_PAD_LEFT), -4);
        }

        $ipv6addr = implode(":", $ipaddr);

        // Extract IPv4 address.
        if (substr($ipv6addr, 0, 30) == "0000:0000:0000:0000:0000:ffff:") {
            $ipv4addr = hexdec(substr($ipv6addr, 30, 2)).".".hexdec(substr($ipv6addr, 32, 2)).".".hexdec(substr($ipv6addr, 35, 2)).".".hexdec(substr($ipv6addr, 37, 2));
        }

        // Make a short IPv6 address.
        $shortipv6 = $ipv6addr;
        $pattern = "0000:0000:0000:0000:0000:0000:0000";
        do {
            $shortipv6 = str_replace($pattern, ":", $shortipv6);
            $pattern = substr($pattern, 5);
        } while (strlen($shortipv6) == 39 && $pattern != "");

        $shortipv6 = explode(":", $shortipv6);
        foreach ($shortipv6 as $num => $segment) {
            if ($segment != "") {
                $shortipv6[$num] = strtolower(dechex(hexdec($segment)));
            }
        }

        $shortipv6 = implode(":", $shortipv6);

        return array("ipv6" => $ipv6addr, "shortipv6" => $shortipv6, "ipv4" => $ipv4addr);
    }
}