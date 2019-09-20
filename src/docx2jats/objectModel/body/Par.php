<?php namespace docx2jats\objectModel\body;

/**
 * @file src/docx2jats/objectModel/body/Par.php
 *
 * Copyright (c) 2018-2019 Vitalii Bezsheiko
 * Distributed under the GNU GPL v3.
 *
 * @brief represent paragraph in OOXML, includes: regular paragraph, lists, heading and other paragraph styles
 */

use docx2jats\objectModel\DataObject;
use docx2jats\objectModel\Document;

class Par extends DataObject {
	const DOCX_PAR_REGULAR = 1;
	const DOCX_PAR_HEADING = 2;
	const DOCX_PAR_LIST = 3;
	const DOCX_LIST_START = 'listStart';
	const DOCX_LIST_END = 'listEnd';
	const DOCX_LIST_HAS_SUBLIST = 'hasSublist';
	const DOCX_LIST_ITEM_ID = 'itemId';

	private $type = array(); // const
	private $properties = array();
	private $text = array();
	public static $headings = array("heading", "heading 1", "heading 2", "heading 3", "heading 4", "heading 5", "heading 6", "title");

	/* @var $headingLevel int */
	private $headingLevel;

	/* @var $numberingLevel int */
	private $numberingLevel;

	/* @var $numberingId int */
	private $numberingId;

	private $numberingItemProp = array();

	public function __construct(\DOMElement $domElement) {
		parent::__construct($domElement);
		$this->defineType();
		$this->properties = $this->setProperties('w:pPr/child::node()');
		$this->text = $this->setContent('w:r|w:hyperlink');
		$this->type = $this->defineType();
		$this->headingLevel = $this->setHeadingLevel();
		$this->numberingLevel = $this->setNumberingLevel();
		$this->numberingId = $this->setNumberingId();
		$this->numberingItemProp = $this->setNumberingItemProp();
	}

	/**
	 * @return array
	 */
	public function getProperties(): array {
		return $this->properties;
	}


	/**
	 * @return array
	 */
	public function getContent(): array {
		return $this->text;
	}

	/**
	 * @return array
	 */
	public function getType() {
		return $this->type;
	}

	protected function setContent(string $xpathExpression) {
		$content = array();
		$contentNodes = $this->getXpath()->query($xpathExpression, $this->getDomElement());
		foreach ($contentNodes as $contentNode) {
			if ($contentNode->nodeName === "w:r") {
				$text = new Text($contentNode);
				$content[] = $text;
			} elseif ($contentNode->nodeName === "w:hyperlink") {
				$children = $this->getXpath()->query('child::node()', $contentNode);
				foreach ($children as $child) {
					$href = new Text($child);
					$href->addType($href::DOCX_TEXT_EXTLINK);
					$href->setLink();
					$content[] = $href;
				}
			}
		}

		return $content;
	}

	/**
	 * @return array
	 */
	private function defineType() {
		$type = array();
		$styles = $this->getXpath()->query('w:pPr/w:pStyle/@w:val', $this->getDomElement());
		if ($this->isOnlyChildNode($styles)) {

			// Find associated style in style.xml; TODO explore consistency for different languages
			$associatedStyle = Document::getElementStyling(Document::DOCX_STYLES_PARAGRAPH, $styles[0]->nodeValue);

			// Fallback to the node content if styles.xml doesn't exist
			if (!$associatedStyle) {
				$associatedStyle = $styles[0]->nodeValue;
			}

			if (in_array(strtolower($associatedStyle), self::$headings)) {
				$type[] = self::DOCX_PAR_HEADING;
			}

		}

		$numberingNode = $this->getXpath()->query('w:pPr/w:numPr', $this->getDomElement());
		if ($this->isOnlyChildNode($numberingNode) && !in_array(self::DOCX_PAR_HEADING, $type)) { // do not include headings to lists
			$type[] = self::DOCX_PAR_LIST;
		}

		if (empty($type)) {
			$type[] = self::DOCX_PAR_REGULAR;
		}

		return $type;
	}

	/**
	 * @return int $level
	 */
	private function setHeadingLevel() {
		$level = 0;
		$styleString = '';
		if (in_array(self::DOCX_PAR_HEADING, $this->type )) {
			$styles = $this->getXpath()->query('w:pPr/w:pStyle/@w:val', $this->getDomElement());
			if ($this->isOnlyChildNode($styles)) {
				$styleString = $styles[0]->nodeValue;
			}
		}

		// Not a heading if empty
		if (empty($styleString)) return $level;

		preg_match_all('/\d+/', $styleString, $matches);

		// Treat headings without a number as the 1st level headings
		if (empty($matches[0])) return $level+1;

		$level = intval(implode('', $matches[0]));

		return $level;
	}

	/**
	 * @return int
	 */
	public function getHeadingLevel(): int {
		return $this->headingLevel;
	}

	/**
	 * @return int
	 */
	private function setNumberingLevel(): int {
		$numberingLevel = 0;
		$numberString = '';
		if (in_array(self::DOCX_PAR_LIST, $this->type)) {
			$numberNode = $this->getXpath()->query('w:pPr/w:numPr/w:ilvl/@w:val', $this->getDomElement());
			if ($this->isOnlyChildNode($numberNode)) {
				$numberString = $numberNode[0]->nodeValue;
			}
		}

		if (empty($numberString)) return $numberingLevel;

		$numberingLevel = intval($numberString);

		return $numberingLevel;
	}

	/**
	 * @return int
	 */
	public function getNumberingLevel(): int {
		return $this->numberingLevel;
	}

	/**
	 * @return int
	 */
	private function setNumberingId(): int {
		$numberingId = 0;
		$numberString = '';
		if (in_array(self::DOCX_PAR_LIST, $this->type)) {
			$numberNode = $this->getXpath()->query('w:pPr/w:numPr/w:numId/@w:val', $this->getDomElement());
			if ($this->isOnlyChildNode($numberNode)) {
				$numberString = $numberNode[0]->nodeValue;
			}
		}

		if (empty($numberString)) return $numberingId;

		$numberingId = intval($numberString);

		return $numberingId;
	}


	/**
	 * @return int
	 */
	public function getNumberingId(): int {
		return $this->numberingId;
	}

	/**
	 * @return array
	 */
	private function setNumberingItemProp(): array {

		$propArray = array();
		$itemDimensionalId = array_fill(0, $this->getNumberingLevel()+1, 0);

		if (!in_array(self::DOCX_PAR_LIST, $this->getType())) return $propArray;

		$propArray = array(self::DOCX_LIST_START => false, self::DOCX_LIST_END => false, self::DOCX_LIST_HAS_SUBLIST => false, self::DOCX_LIST_ITEM_ID => $itemDimensionalId);

		$numberNode = $this->getXpath()->query('w:pPr/w:numPr/w:ilvl/@w:val', $this->getDomElement())[0];
		$number = intval($numberNode->nodeValue);

		// Properties based on the previous node's level
		$prevNumberNode = $this->getXpath()->query('preceding-sibling::w:p[1]/w:pPr/w:numPr/w:ilvl/@w:val', $this->getDomElement());
		if ($this->isOnlyChildNode($prevNumberNode)) {
			$prevNumber =  intval($prevNumberNode[0]->nodeValue);
			if ($prevNumber < $number) $propArray[self::DOCX_LIST_START] = true;
		} else {
			$propArray[self::DOCX_LIST_START] = true;
		}

		// Properties based on the following node's level
		$nextNumberNode = $this->getXpath()->query('following-sibling::w:p[1]/w:pPr/w:numPr/w:ilvl/@w:val', $this->getDomElement());
		if ($this->isOnlyChildNode($nextNumberNode)) {
			$nextNumber = intval($nextNumberNode[0]->nodeValue);
			if ($nextNumber < $number) $propArray[self::DOCX_LIST_END] = true;
			if ($nextNumber > $number) $propArray[self::DOCX_LIST_HAS_SUBLIST] = true;
		} else {
			$propArray[self::DOCX_LIST_END] = true;
		}

		// Determining dimensional ID based on Node's level and the number of preceding nodes on the same level and levels above

		$numberingLevel = $this->getNumberingLevel();
		while (!($numberingLevel < 0)) {
			$previousSiblingSameList = $this->getXpath()->query('preceding-sibling::w:p/w:pPr/w:numPr/w:numId[@w:val="' . $this->getNumberingId() . '"]', $this->getDomElement());
			$countSameLevel = 0;
			if (count($previousSiblingSameList) > 0) {
				foreach ($previousSiblingSameList as $sameListItem) {
					$previousSiblingSameId = $this->getXpath()->query('parent::w:numPr/w:ilvl[@w:val="' . $numberingLevel . '"]', $sameListItem);
					if ($this->isOnlyChildNode($previousSiblingSameId)) $countSameLevel++;
				}
			}

			$itemDimensionalId[$numberingLevel] = $countSameLevel;
			$numberingLevel--;
		}

		$propArray[self::DOCX_LIST_ITEM_ID] = $itemDimensionalId;

		return $propArray;
	}

	public function getNumberingItemProp() {
		return $this->numberingItemProp;
	}
}
