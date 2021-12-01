<?php
    // force some MIME types that the PHP built-in server isn't correctly handling
    $mime = array(
        '.svg' => 'image/svg+xml',
        '.css' => 'text/css; charset=UTF-8',
        '.js' => 'application/javascript',
        '.php' => 'text/html'
    );

    // SERVER_NAME has some pretty odd behaviour, overwrite it, otherwise some scripts can't detect the real domain
    // Read: https://shiflett.org/blog/2006/server-name-versus-http-host
    $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];

    $xml = simplexml_load_file('web.config');

    if(isset($xml->{'system.webServer'})) {
        $webServer_conf = $xml->{'system.webServer'};

        // parse other explicit MIME types specified in web.config and add to $mime
        if(isset($webServer_conf->staticContent)) {
            $staticContent = $webServer_conf->staticContent;
            
            if(isset($staticContent->mimeMap)) {
                foreach ($staticContent->mimeMap as $value) {
                    $mime[(string)$value['fileExtension']] = (string) $value['mimeType'];
                }
            }
        }

        // add custom HTTP response headers as defined in web.config
        if(isset($webServer_conf->httpProtocol->customHeaders)) {
            $customHeaders = $webServer_conf->httpProtocol->customHeaders;

            if(isset($customHeaders->add)) {
                foreach ($customHeaders->add as $value) {
                    header((string)$value['name'] . ': ' . (string)$value['value']);
                }
            }
        }

        // start parsing URL rewrite/redirect rules
        if(isset($webServer_conf->rewrite->rules)) {
            $rules = $webServer_conf->rewrite->rules;

            if(isset($rules->rule)) {
                foreach($rules->rule as $rule) {
                    // We have to convert any individual '-' in the regex to '\-' due to PCRE2 in PHP>=7.3
                    // We'll have to use regex to deal with the regex from web.config to prevent replacing '\-' to '\\-'
                    // Read: https://php.watch/versions/7.3/pcre2
                    $regex = preg_replace('/(?<=[^\\\\])\\-/', '\-', (string)$rule->match['url']);

                    // compare to see if the currently browsed URL matches the one in the current <rule> in web.config

                    // We use '#' as the regex delimeter so we don't have to escape '/' in the regex
                    // Trim away the beginning slash from REQUEST_URI because it doesn't exist during IIS's comparison
                    // also remove the query string from behind the REQUEST_URI (like something.php?a=b&c=d)
                    if(preg_match('#'.$regex.'#', strtok(ltrim($_SERVER['REQUEST_URI'], '/'), '?'), $matches)) {

                        // Start checking to see if it matches ALL conditions specified in <conditions> in web.config
                        // Intialize with true first
                        $meet_conditions = true;

                        if(isset($rule->conditions)) {
                            foreach ($rule->conditions->add as $value) {

                                // intialize the currently checked <conditions> true first
                                $meet_condition = false;

                                // replace IIS variables and URL regex matches in the 'input' attribute
                                $input = parse_template((string) $value['input'], $matches);

                                if(isset($value['matchType'])) {
                                    switch ($value['matchType']) {
                                        case 'IsFile':
                                            $meet_condition = file_exists($input);
                                            break;

                                        case 'IsDirectory':
                                            $meet_condition = is_dir($input);
                                            break;
                                    }
                                }

                                // check if the 'input' data matches the specified 'pattern' regex
                                if(isset($value['pattern'])) {
                                    $meet_condition = preg_match('#'.(string)$value['pattern'].'#', $input);
                                }

                                // if negate="true", then flip the boolean
                                if(isset($value['negate']) && (string)$value['negate'] === 'true') {
                                    $meet_condition = !$meet_condition;
                                }

                                // compare the currently checked condition with the previously checked conditions
                                $meet_conditions = $meet_conditions && $meet_condition;

                                // don't have to check more conditions if it's already false
                                if(!$meet_conditions) {
                                    break;
                                }
                            }
                        }

                        // perform the action if it matches ALL <conditions>
                        if($meet_conditions) {

                            // replace IIS templates and {R:*} regex matches in final action URL
                            $url = parse_template((string) $rule->action['url'], $matches);

                            switch($rule->action['type']) {
                                case 'Rewrite':
                                    $url = parse_url($url);

                                    // handle cases where the directory is like '/page/file.php' to just 'page/file.php'
                                    // since it gets assumed as wanting to access the root directory in UNIX
                                    $path = ltrim($url['path'], '/');

                                    // IIS allows rewriting to just a folder, we have to find the index file for that folder
                                    if (is_dir($path)) {
                                        if (file_exists($path . '/index.html')) $path .= '/index.html';
                                        if (file_exists($path . '/index.php')) $path .= '/index.php';
                                    }

                                    // get the extension of the currently rewritten file in order to search MIME type
                                    $ext = pathinfo($path, PATHINFO_EXTENSION);

                                    // add MIME type to Content-Type HTTP response header
                                    if (isset($mime['.' . $ext])) {
                                        header('Content-Type: ' . $mime['.' . $ext]);
                                    }
                                    else {
                                        header('Content-Type: ' . mime_content_type($path));
                                    }

                                    // if it gets rewritten to a PHP file, parse and execute it as PHP
                                    if ($ext === 'php') {
                                        // change the current directory to the directory of the rewritten PHP file so that
                                        // relative paths written in the PHP file would work
                                        chdir(dirname($path));
                                        require($path);
                                        return;
                                    }
                                    else {
                                        // handle static files here

                                        // handle Partial Content requests, such as for videos to be seekable
                                        // Read: https://www.media-division.com/the-right-way-to-handle-file-downloads-in-php/
                                        // (Section 6)
                                        if (isset($_SERVER['HTTP_RANGE'])) {

                                            // Sample HTTP_RANGE: bytes 0-1024 (we need to trim the "bytes " away)
                                            list($start, $end) = explode('-', substr($_SERVER['HTTP_RANGE'], 6));
                                            $filesize = filesize($path);

                                            // send 1000000 bytes (1MB) of the file or less if browser did not specify where to end it
                                            $start = intval($start) ?: 0;
                                            $end = intval($end) ?: intval($start)+min(1000000, $filesize - $start - 1);

                                            header('HTTP/1.1 206 Partial Content');
                                            header('Accept-Ranges: bytes');
                                            header("Content-Range: bytes $start-$end/$filesize");
                                            $length = $end - $start + 1;
                                            header("Content-Length: $length");

                                            // Read and return part of the file
                                            $file = fopen($path, 'rb');
                                            fseek($file, $start);
                                            echo fread($file, $length);
                                            fclose($file);
                                            return;
                                        }
                                        else {
                                            // return static file normally if the browser didn't ask for Partial Content
                                            readfile($path);
                                            return;
                                        }
                                    }
                                    break;

                                case 'Redirect':
                                    header('Location: ' . $url);
                                    return;
                            }
                        }
                    }
                }
            }
        }

        // let the built-in PHP web server handle the request (by returning false) if there's no matches
        return false;
    }

    // replace IIS variables and {R:*} variables in a string to its actual value and return
    function parse_template($string, $matches = []) {

        // replacement for IIS variables with PHP variables
        $iis_replacements = array(
            '{HTTP_HOST}' => $_SERVER['HTTP_HOST'],
            '{APPL_PHYSICAL_PATH}' => $_SERVER['DOCUMENT_ROOT'] . '/',
            '{REQUEST_FILENAME}' => __DIR__ . $_SERVER['PHP_SELF']
        );

        $string = str_replace(array_keys($iis_replacements), array_values($iis_replacements), $string);

        // replacement for {R:*} variables from regex matches passed in from $matches
        for ($i = 0; $i < count($matches); $i++) {
            $string = str_replace('{R:' . $i . '}', $matches[$i], $string);
        }

        // replace '\' (Windows' directory separator) to '/' (UNIX-based OS directory separator)
        $string = str_replace('\\', '/', $string);

        return $string;
    }
?>