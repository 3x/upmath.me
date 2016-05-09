<?php
/**
 * @copyright 2014-2016 Roman Parpalak
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @package   S2 Latex Service
 * @link      http://tex.s2cms.ru
 */

namespace Tex;

/**
 * Class Renderer
 *
 * Runs Latex CLI.
 */
class Renderer implements RendererInterface
{
	const SVG_PRECISION = 5;

	/**
	 * @var TemplaterInterface
	 */
	private $templater;

	private $is_debug = false;
	private $log_dir;
	private $latexCommand;
	private $pngCommand;
	private $svgCommand;
	private $svg2pngCommand;
	private $svg = '', $png = '';

	public function __construct (TemplaterInterface $templater, $tmpDir, $latexCommand, $svgCommand, $pngCommand = null)
	{
		$this->templater    = $templater;
		$this->tmp_dir      = $tmpDir;
		$this->latexCommand = $latexCommand;
		$this->svgCommand   = $svgCommand;
		$this->pngCommand   = $pngCommand;
	}

	public function setDebug ($isDebug)
	{
		$this->is_debug = $isDebug;
	}

	public function setLogDir ($dir)
	{
		$this->log_dir = $dir;
	}

	private function checkFormula($formula)
	{
		foreach (array('\\write', '\\input', '\\usepackage') as $disabledCommand) {
			if (strpos($formula, $disabledCommand) !== false) {
				if ($this->log_dir !== null)
				{
					$logger = new \Katzgrau\KLogger\Logger($this->log_dir);
					$logger->error('Forbidden command: ', array($formula));
					$logger->error('Server vars: ', $_SERVER);
				}
				throw new \Exception('Forbidden commands.');
			}
		}
	}

	public function run ($formula)
	{
		$this->checkFormula($formula);

		$tmp_name = tempnam(TMP_DIR, '');

		$tex_source = $this->templater->run($formula);
		$this->echoDebug(htmlspecialchars($tex_source));

		// Latex
		file_put_contents($tmp_name, $tex_source);
		try {
			list($out, $status) = Lib::ExecWaitTimeout($this->latexCommand . ' ' . $tmp_name . ' 2>&1');
			if ($this->is_debug) {
				echo '<pre>';
				readfile($tmp_name . '.log');
				var_dump($status);
				echo '</pre>';
			}
		}
		catch (\Exception $e) {
			if ($this->log_dir !== null) {
				$logger = new \Katzgrau\KLogger\Logger($this->log_dir);
				$logger->error('Cannot run Latex', array($e->getMessage()));
				$logger->error('source', array($tex_source));
			}
			throw $e;
		}

		$is_latex_error = !file_exists($tmp_name . '.dvi') /*|| $status['exitcode'] !== 0*/;

		if (!file_exists($tmp_name . '.dvi') || isset($status['status_kill'])) {
			// Ohe has to figure out why the process was killed and why no dvi-file is created.
			if ($this->log_dir !== null) {
				$logger = new \Katzgrau\KLogger\Logger($this->log_dir);
				$logger->error('Latex finished incorrectly');
				$logger->error('status', $status + array("file_exists($tmp_name.dvi)" => file_exists($tmp_name . '.dvi')));
				$logger->error('source', array($tex_source));
				$logger->error('trace',  array(file_get_contents($tmp_name.'.log')));
			}
		}

		if ($is_latex_error) {
			$this->dumpDebug($this);
			$this->cleanupTempFiles($tmp_name);
			throw new \Exception('Invalid formula');
		}

		// DVI -> SVG
		$cmd = sprintf($this->svgCommand, $tmp_name);
		$svg_output = shell_exec($cmd);

		$this->dumpDebug($cmd);
		$this->dumpDebug($svg_output);

		$svg = file_get_contents($tmp_name . '.svg');

		// $svg = '...<!--start 19.8752 31.3399 -->...';
		//                                    x        y
		$is_start = preg_match('#<!--start ([\d.]+) ([\d.]+) -->#', $svg, $match_start);
		//                                  x        y        w        h
 		$is_bbox = preg_match('#<!--bbox ([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+) -->#', $svg, $match_bbox);
		if ($is_start && $is_bbox) {
			// SVG contains info about image size and baseline position.
			// Taking into account OUTER_SCALE since coordinates are in the internal scale.
			$depth = round(OUTER_SCALE * (- $match_start[2] + $match_bbox[2] + $match_bbox[4]), self::SVG_PRECISION);
			$height = round(OUTER_SCALE * $match_bbox[4], self::SVG_PRECISION);
			$width = round(OUTER_SCALE * $match_bbox[3], self::SVG_PRECISION);

			// Embedding script providing that info to the parent.
			$script = '<script type="text/ecmascript">if(window.parent.postMessage)window.parent.postMessage("'.$depth.'|'.$width.'|'.$height.'|"+window.location,"*");</script>'."\n";
			$svg = str_replace('<defs>', $script . '<defs>', $svg);
		}

		$this->svg = $svg;

		if ($this->svg2pngCommand) {
			// SVG -> PNG
			ob_start();
			passthru(sprintf($this->svg2pngCommand, $tmp_name));
			$this->png = ob_get_clean();
		}
		elseif ($this->pngCommand) {
			// DVI -> PNG
			exec(sprintf($this->pngCommand, $tmp_name));
			$this->png = file_get_contents($tmp_name . '.png');
		}

		// Cleaning up
		$this->cleanupTempFiles($tmp_name);
	}

	public function getSVG ()
	{
		return $this->svg;
	}

	public function getPNG ()
	{
		return $this->png;
	}

	/**
	 * @param string $tmp_name
	 */
	private function cleanupTempFiles ($tmp_name)
	{
		foreach (['', '.log', '.aux', '.dvi', '.svg', '.png'] as $ext) {
			@unlink($tmp_name . $ext);
		}
	}

	public function setSVG2PNGCommand ($command)
	{
		$this->svg2pngCommand = $command;
	}

	/**
	 * @param $output
	 */
	private function dumpDebug($output)
	{
		if ($this->is_debug) {
			echo '<pre>';
			var_dump($output);
			echo '</pre>';
		}
	}

	/**
	 * @param $output
	 */
	private function echoDebug($output)
	{
		if ($this->is_debug) {
			echo '<pre>';
			echo $output;
			echo '</pre>';
		}
	}
}
