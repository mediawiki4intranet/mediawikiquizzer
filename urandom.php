<?php

// "Real random number" generator
// (c) Vitaliy Filippov, 2010-2011

if (!function_exists('urandom'))
{
    function urandom($nbytes = 16)
    {
        $pr_bits = '';
        // Unix/Linux platform?
        $fp = @fopen('/dev/urandom', 'rb');
        if ($fp !== FALSE)
        {
            $pr_bits = @fread($fp, $nbytes);
            @fclose($fp);
        }
        // MS-Windows platform?
        elseif (@class_exists('COM'))
        {
            // http://msdn.microsoft.com/en-us/library/aa388176(VS.85).aspx
            try
            {
                $com = new COM('CAPICOM.Utilities.1');
                if (method_exists($com, 'GetRandom'))
                    $pr_bits = base64_decode($com->GetRandom($nbytes,0));
                else
                {
                    $com = new COM('System.Security.Cryptography.RNGCryptoServiceProvider');
                    if (method_exists($com, 'GetBytes'))
                        $pr_bits = base64_decode($com->GetBytes($nbytes));
                }
            }
            catch (Exception $ex)
            {
            }
        }
        if (!strlen($pr_bits))
            for ($i = 0; $i < $nbytes; $i++)
                $pr_bits .= chr(mt_rand(0, 255));
        return $pr_bits;
    }
}
