<?php
$sock = stream_socket_server("tcp://127.0.0.1:1025", $errno, $errstr);
if (!$sock) die("failed $errstr");

// Send test in background
exec('php send_test.php > /dev/null 2>&1 &');

$conn = stream_socket_accept($sock, 5);
if (!$conn) die("no conn");

fwrite($conn, "220 Welcome\r\n");
$out = "";
while ($line = fgets($conn)) {
    $out .= $line;
    if (strpos($line, "EHLO") === 0) fwrite($conn, "250 OK\r\n");
    if (strpos($line, "MAIL FROM") === 0) fwrite($conn, "250 OK\r\n");
    if (strpos($line, "RCPT TO") === 0) fwrite($conn, "250 OK\r\n");
    if (strpos($line, "DATA") === 0) {
        fwrite($conn, "354 Go ahead\r\n");
        while ($dline = fgets($conn)) {
            $out .= $dline;
            if (trim($dline) === ".") {
                fwrite($conn, "250 OK\r\n");
                break 2;
            }
        }
    }
    if (strpos($line, "QUIT") === 0) {
        fwrite($conn, "221 Bye\r\n");
        break;
    }
}
fclose($conn);
fclose($sock);
file_put_contents('email_output.txt', $out);
