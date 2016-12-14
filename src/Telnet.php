<?php
namespace miyahan\network;

/**
 * Telnet class
 *
 * Used to execute remote commands via telnet connection
 * Usess sockets functions and fgetc() to process result
 *
 * All methods throw Exceptions on error
 */
class Telnet
{
    private $host;
    private $port;
    private $timeout;
    private $stream_timeout_sec;
    private $stream_timeout_usec;

    private $socket = null;
    private $buffer = null;
    private $prompt;
    private $errno;
    private $errstr;
    private $strip_prompt = true;
    private $eol = "\r\n";

    private $delay = 0;

    private $NULL;
    private $DC1;
    private $WILL;
    private $WONT;
    private $DO;
    private $DONT;
    private $IAC;

    private $global_buffer;

    const TELNET_ERROR = false;
    const TELNET_OK = true;

    /**
     * Constructor. Initialises host, port and timeout parameters
     * defaults to localhost port 23 (standard telnet port)
     *
     * @param string $host Host name or IP addres
     * @param int $port TCP port number
     * @param int $timeout Connection timeout in seconds
     * @param float $stream_timeout Stream timeout in decimal seconds
     * @throws \Exception
     */
    public function __construct($host = '127.0.0.1', $port = 23, $timeout = 10, $stream_timeout = 1.0)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->setStreamTimeout($stream_timeout);

        // set some telnet special characters
        $this->NULL = chr(0);
        $this->DC1 = chr(17);
        $this->WILL = chr(251);
        $this->WONT = chr(252);
        $this->DO = chr(253);
        $this->DONT = chr(254);
        $this->IAC = chr(255);

        // open global buffer stream
        $this->global_buffer = new \SplFileObject('php://temp', 'r+b');

        $this->connect();
    }

    /**
     * Destructor. Cleans up socket connection and command buffer
     *
     * @return void
     */
    public function __destruct()
    {
        // clean up resources
        $this->disconnect();
        $this->buffer = null;
    }

    /**
     * Attempts connection to remote host. Returns true if successful.
     *
     * @return bool
     * @throws \Exception
     */
    public function connect()
    {
        // check if we need to convert host to IP
        if (!preg_match('/([0-9]{1,3}\\.){3,3}[0-9]{1,3}/', $this->host)) {
            $ip = gethostbyname($this->host);

            if ($this->host == $ip) {
                throw new \Exception("Cannot resolve $this->host");
            } else {
                $this->host = $ip;
            }
        }

        // attempt connection - suppress warnings
        $this->socket = @fsockopen($this->host, $this->port, $this->errno, $this->errstr, $this->timeout);
        if (!$this->socket) {
            throw new \Exception("Cannot connect to $this->host on port $this->port");
        }
        stream_set_blocking($this->socket, false);

        if (!empty($this->prompt)) {
            $this->waitPrompt();
        }

        return self::TELNET_OK;
    }

    /**
     * Closes IP socket
     *
     * @return bool
     * @throws \Exception
     */
    public function disconnect()
    {
        if ($this->socket) {
            if (!fclose($this->socket)) {
                throw new \Exception("Error while closing telnet socket");
            }
            $this->socket = null;
        }
        return self::TELNET_OK;
    }

    /**
     * Executes command and returns a string with result.
     * This method is a wrapper for lower level private methods
     *
     * @param string $command Command to execute
     * @param boolean $add_newline Default true, adds newline to the command
     * @return string Command result
     */
    public function exec($command, $add_newline = true)
    {
        $this->write($command, $add_newline);
        $this->waitPrompt();
        return $this->getBuffer();
    }

    /**
     * Attempts login to remote host.
     * This method is a wrapper for lower level private methods and should be
     * modified to reflect telnet implementation details like login/password
     * and line prompts. Defaults to standard unix non-root prompts
     *
     * @param string $username Username
     * @param string $password Password
     * @param string $host_type Type of destination host
     * @return bool
     * @throws \Exception
     */
    public function login($username, $password, $host_type = 'linux')
    {
        $this->delay = 0;
        switch ($host_type) {
            case 'linux':  // General Linux/UNIX
                $user_prompt = 'login:';
                $pass_prompt = 'Password:';
                $prompt_reg = '\$';
                break;

            case 'ios':    // Cisco IOS, IOS-XE, IOS-XR
                $user_prompt = 'Username:';
                $pass_prompt = 'Password:';
                $prompt_reg = '[>#]';
                break;

            case 'eoc-master':    // eoc master
                $user_prompt = 'Login:';
                $pass_prompt = 'Password:';
                $prompt_reg = '(>|:|\)#)';
                break;

            case 'eoc-modem':    // eoc modem
                $user_prompt = 'login:';
                $pass_prompt = 'Password:';
                $prompt_reg = '#';
                $this->delay = 100000;
                break;

            case 'junos':  // Juniper Junos OS
                $user_prompt = 'login:';
                $pass_prompt = 'Password:';
                $prompt_reg = '[%>#]';
                break;

            case 'alaxala': // AlaxalA, HITACHI
                $user_prompt = 'login:';
                $pass_prompt = 'Password:';
                $prompt_reg = '[>#]';
                break;

            default:
                throw new \Exception('Host type is invalid');
                break;
        }

        try {
            // username
            if (!empty($username)) {
                $this->setPrompt($user_prompt);
                $this->waitPrompt();
                $this->write($username);
            }

            // password
            $this->setPrompt($pass_prompt);
            $this->waitPrompt();
            $this->write($password);

            // wait prompt
            if ($this->delay)
                usleep($this->delay);
            $this->setRegexPrompt($prompt_reg);
            $this->waitPrompt();
        } catch (\Exception $e) {
            throw new \Exception("Login failed.");
        }

        return self::TELNET_OK;
    }

    /**
     * Sets the string of characters to respond to.
     * This should be set to the last character of the command line prompt
     *
     * @param string $str String to respond to
     * @return boolean
     */
    public function setPrompt($str)
    {
        return $this->setRegexPrompt(preg_quote($str, '/'));
    }

    /**
     * Sets a regex string to respond to.
     * This should be set to the last line of the command line prompt.
     *
     * @param string $str Regex string to respond to
     * @return boolean
     */
    public function setRegexPrompt($str)
    {
        $this->prompt = $str;
        return self::TELNET_OK;
    }

    /**
     * Sets the stream timeout.
     *
     * @param float $timeout
     * @return void
     */
    public function setStreamTimeout($timeout)
    {
        $this->stream_timeout_usec = (int)(fmod($timeout, 1) * 1000000);
        $this->stream_timeout_sec = (int)$timeout;
    }

    /**
     * Set if the buffer should be stripped from the buffer after reading.
     *
     * @param $strip boolean if the prompt should be stripped.
     * @return void
     */
    public function stripPromptFromBuffer($strip)
    {
        $this->strip_prompt = $strip;
    } // function stripPromptFromBuffer

    /**
     * Gets character from the socket
     *
     * @return string $c character string
     */
    protected function getc()
    {
        stream_set_timeout($this->socket, $this->stream_timeout_sec, $this->stream_timeout_usec);
        $c = fgetc($this->socket);
        $this->global_buffer->fwrite($c);
        return $c;
    }

    /**
     * Clears internal command buffer
     *
     * @return void
     */
    public function clearBuffer()
    {
        $this->buffer = '';
    }

    /**
     * Reads characters from the socket and adds them to command buffer.
     * Handles telnet control characters. Stops when prompt is ecountered.
     *
     * @param string $prompt
     * @return bool
     * @throws \Exception
     */
    protected function readTo($prompt)
    {
        if (!$this->socket) {
            throw new \Exception("Telnet connection closed");
        }

        // clear the buffer
        $this->clearBuffer();

        $read = array($this->socket);
        $write = null;
        $expect = null;
        stream_select($read, $write, $expect, $this->stream_timeout_sec, $this->stream_timeout_usec);

        $until_t = time() + $this->timeout;
        do {
            // time's up (loop can be exited at end or through continue!)
            if (time() > $until_t) {
                throw new \Exception("Couldn't find the requested : '$prompt' within {$this->timeout} seconds");
            }

            $c = $this->getc();


            if ($c === null)
            {
                return self::TELNET_OK;
            }

            usleep(1);

            if ($c === false) {
                // consume rest of the charaters
                while ($c = fgetc($this->socket));
                if (empty($prompt)) {
                    return self::TELNET_OK;
                }
                throw new \Exception("Couldn't find the requested : '" . $prompt . "', it was not in the data returned from server: " . $this->buffer);
            }

            // Interpreted As Command
            if ($c == $this->IAC) {
                if ($this->delay)
                    usleep($this->delay);
                if ($this->negotiateTelnetOptions()) {
                    continue;
                }
            }

            // append current char to global buffer
            $this->buffer .= $c;

            //var_dump($this->buffer.' '.$c);

            // we've encountered the prompt. Break out of the loop
            if (!empty($prompt) && preg_match("/{$prompt}$/", $this->buffer)) {
                // consume extra characters afer the prompt
                while (fgetc($this->socket));
                return self::TELNET_OK;
            }

        } while ($c != $this->NULL || $c != $this->DC1);
    }

    /**
     * Write command to a socket
     *
     * @param string $buffer Stuff to write to socket
     * @param boolean $add_newline Default true, adds newline to the command
     * @return bool
     * @throws \Exception
     */
    protected function write($buffer, $add_newline = true)
    {
        if (!$this->socket) {
            throw new \Exception("Telnet connection closed");
        }

        // clear buffer from last command
        $this->clearBuffer();

        if ($add_newline == true) {
            $buffer .= $this->eol;
        }

        $read = null;
        $write = array($this->socket);
        $expect = null;
        stream_select($read, $write, $expect, $this->stream_timeout_sec, $this->stream_timeout_usec);

        $this->global_buffer->fwrite($buffer);

        if (!fwrite($this->socket, $buffer) < 0) {
            throw new \Exception("Error writing to socket");
        }

        return self::TELNET_OK;
    }

    /**
     * Returns the content of the command buffer
     *
     * @return string Content of the command buffer
     */
    public function getBuffer()
    {
        // Remove all carriage returns from line breaks
        $buf = preg_replace('/\r\n|\r/', "\n", $this->buffer);
        // Cut last line from buffer (almost always prompt)
        if ($this->strip_prompt) {
            $buf = explode("\n", $buf);
            unset($buf[count($buf) - 1]);
            $buf = implode("\n", $buf);
        }
        return trim($buf);
    }

    /**
     * Returns the content of the global command buffer
     *
     * @return string Content of the global command buffer
     */
    public function getGlobalBuffer()
    {
        $this->global_buffer->rewind();
        $global_buffer = '';
        while (!$this->global_buffer->eof())
        {
            $global_buffer .= $this->global_buffer->fread(1024);
        }
        return $global_buffer;
    }

    /**
     * Telnet control character magic
     *
     * @return bool
     * @throws \Exception
     * @internal param string $command Character to check
     */
    protected function negotiateTelnetOptions()
    {
        $c = $this->getc();

        if ($c != $this->IAC) {
            if (($c == $this->DO) || ($c == $this->DONT)) {
                $opt = $this->getc();
                fwrite($this->socket, $this->IAC . $this->WONT . $opt);
            } else if (($c == $this->WILL) || ($c == $this->WONT)) {
                $opt = $this->getc();
                fwrite($this->socket, $this->IAC . $this->DONT . $opt);
            } else {
                throw new \Exception('Error: unknown control character ' . ord($c));
            }
        } else {
            throw new \Exception('Error: Something Wicked Happened');
        }

        return self::TELNET_OK;
    }

    /**
     * Reads socket until prompt is encountered
     */
    protected function waitPrompt()
    {
        return $this->readTo($this->prompt);
    }
}
