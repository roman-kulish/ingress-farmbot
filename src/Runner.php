<?php

/**
 * Class Runner
 */
final class Runner
{
    /**
     * Process Id
     *
     * @var resource
     */
    protected $process = null;

    /**
     * Pipes
     *
     * @var resource[]
     */
    protected $pipes = null;

    /**
     * Constructor
     *
     * @param string $path Base directory
     */
    public function __construct($path)
    {
        $this->openProcess($path);
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Open Runner process
     *
     * @param string $path
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    protected function openProcess($path)
    {
        if (( $path = realpath($path) ) === false || !is_dir($path)) {
            throw new InvalidArgumentException('Invalid base directory path');
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (( $base = realpath($path . 'bin') ) === false) {
            throw new RuntimeException('"bin" directory does not exist');
        } else if (( $guavaJar = realpath($path . 'jar/guava.jar') ) === false) {
            throw new RuntimeException('"jar/guava.jar" does not exist');
        } else if (( $s2geometryJar = realpath($path . 'jar/s2geometry.jar') ) === false) {
            throw new RuntimeException('"jar/s2geometry.jar" does not exist');
        }

        $cmd = 'java -cp "' . implode(PATH_SEPARATOR, array($base, $guavaJar, $s2geometryJar)) . '" Runner';

        $descr = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => STDERR
        );

        if (( $this->process = proc_open($cmd, $descr, $this->pipes) ) === false) {
            throw new RuntimeException('Cannot create Runner process');
        }
    }

    /**
     * Get surrounding geocells
     *
     * @param LatLng $sw SW point
     * @param LatLng $ne NE point
     * @return array
     */
    public function getCells(LatLng $sw, LatLng $ne)
    {
        return $this->runCommand(sprintf("cells %f %f %f %f", $sw->lat, $sw->lng, $ne->lat, $ne->lng));
    }

    /**
     * Parse energy glob
     *
     * @param string $glob Glob
     * @return array
     */
    public function parseGlob($glob)
    {
        list($lat, $lng, $amount) = $this->runCommand( sprintf('glob %s', $glob) );
        return array( new LatLng($lat, $lng), $amount );
    }

    /**
     * Close the process
     */
    protected function close()
    {
        if ( is_resource($this->process) ) {
            fclose($this->pipes[0]);
            fclose($this->pipes[1]);

            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    /**
     * Run the command and return array of lines outputted by the Runner
     *
     * @param string $command Command to run
     * @return array
     * @throws RuntimeException
     */
    protected function runCommand($command)
    {
        if ( !is_resource($this->process) || !$this->isRunning() ) {
            throw new RuntimeException('The Runner process has been terminated');
        } else if ( !fwrite($this->pipes[0], trim($command) . "\n") ) {
            throw new RuntimeException('Cannot write to the Runner input');
        }

        $buffer = array();

        while( $this->isRunning() ) {
            if (( $line = fgets($this->pipes[1]) ) === false) {
                throw new RuntimeException('Error reading from the Runner output');
            }

            if ( empty($line) ) {
                continue;
            } else if (( $line = trim($line) ) == '.') {
                break;
            }

            $buffer[] = $line;
        }

        return $buffer;
    }

    /**
     * Check process status
     *
     * @return bool
     */
    protected function isRunning()
    {
        $status = proc_get_status($this->process);
        return ($status['running'] !== -1 && $status['running'] != false);
    }
}