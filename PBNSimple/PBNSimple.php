<?php
/**
 * @author Foppe HEMMINGA
 * @copyright (c) 2011-2012 by Foppe HEMMINGA
 * @license GPLv2 - {@link http://www.gnu.org/licenses/gpl-2.0.html}
 *
 * @version 0.3
 * @date 2012-01-04
 *
 * This file is part of the PBN Simple plugin
 * for the Joomla! framework {@link http://http://www.joomla.org/}
 */
defined( '_JEXEC' ) or die( 'Restricted access' );

jimport( 'joomla.plugin.plugin' );

class plgContentPBNSimple extends JPlugin 
{
	/**
	 * This function is the Joomla! 1.7 style
	 * trigger function 
	 * 
	 * @param ? $context
	 * @param mixed[] $row
	 * @param mixed[] $params
	 * @param int $page
	 * @return void
	 */
 	public function onContentPrepare( $context, &$row, &$params, $page = 0 )  
	{
		// $start = microtime( true );
		
		// This is the simple version. A subsequent version may do
		// something with the meta-data
		$row->text = preg_replace( '/(<p>)?%PBN.*/', '', $row->text );
		$row->text = preg_replace( '/(<p>)?\[Event .*/', '', $row->text );
		$row->text = preg_replace( '/(<p>)?\[Site .*/', '', $row->text );
		$row->text = preg_replace( '/(<p>)?\[Date .*/', '', $row->text );
	
		// First pass: group all matching [Board], [Dealer],
		// [Vulnerable], [Deal] tags
		// The 'm' flag is multi-line
		// The 's' flag: '.' matches any token including whitespace of sorts
		// The 'U' flag is non-greedy
		$pattern = '/\[Board(.*)\[Dealer(.*)\[Vulnerable(.*)\[Deal(.*)"\]/msU';		
		$hits_big = preg_match_all( $pattern, $row->text, $full_pbn );
		
		if ( $hits_big != 0 )
		{
			// Let an intermediate function handle this mess
			$clean_pbn = $this->process_raw_pbn( $full_pbn );
			// Example of $clean_pbn
			// array(1) { 
			//	[0]=> array(4) { 
			//		[0]=> string(2) "10" 
			//		[1]=> string(1) "N" 
			//		[2]=> string(3) "All" 
			//		[3]=> string(78) "[Deal "N:J62.K96.A9.QT982 
			//			A83.AT82.Q875.43 T754.J7543.2.K76 KQ9.Q.KJT643.AJ5"]" 
			//	}
			// }
			
			for ( $i = 0; $i < count( $clean_pbn ); $i += 1 )
			{
				$pbn_temp[0][] = $clean_pbn[$i][3];
			}

			$deal = $this->process_pbn( $pbn_temp );
			foreach ( $deal as $this_deal )
			{
				// Regenerate the PBN string for a precise
				// pattern in the upcoming preg_replace()
				$pbn_string = $this->regenerate_pbn_string( $this_deal );

				// Pattern and replace arrays for the upcoming preg_replace
				$pbn_pattern[] = '/\[Deal "[NESW]:' . $pbn_string . '"\]/i';
			}
			
			for ( $i = 0; $i < count( $clean_pbn ); $i += 1 )
			{
				$html[] = $this->content_generateHTML( $deal[$i], 
					$clean_pbn[$i][0], $clean_pbn[$i][1], $clean_pbn[$i][2] );					
			}

			// The batch-replacement takes place here.
			$row->text = preg_replace( $pbn_pattern, $html, $row->text );				
			unset( $pbn_temp, $deal, $pbn_string, $pbn_pattern, $html );
		}
		// Remove meta-data from the text
		$row->text = preg_replace( '$(<p>)?\[Board "(\d+)"\](</p>)?$', '',
			$row->text );
		$row->text = preg_replace( '$(<p>)?\[Dealer "\w"\](</p>)?$i', '',
			$row->text );
		$row->text = preg_replace( '$(<p>)?\[Vulnerable "\w+"\](</p>)?$i',
			'', $row->text );

		// Find dangling deals without meta information in second pass
		$hits = preg_match_all( '/\[Deal ".*"\]/i', $row->text, $deals );

		if( $hits != 0 ) // Very important line!
		{
			// We found the deals, so process it with various helper-functions
			$deal = $this->process_pbn( $deals );

			foreach ( $deal as $this_deal )
			{
				// Regenerate the PBN string for a precise
				// pattern in the upcoming preg_replace()
				$pbn_string = $this->regenerate_pbn_string( $this_deal );

				// Pattern and replace arrays for the upcoming preg_replace
				$pbn_pattern[] = '/\[Deal "[NESW]:' . $pbn_string . '"\]/i';
				$html[] = $this->content_generateHTML( $this_deal, $board,
					$dealer, $vuln );
			}
			// The batch-replacement takes place here.
			$row->text = preg_replace( $pbn_pattern, $html, $row->text );
		}
		
		// This block replaces :s: etc in image symbols
		// @TODO Check if it's better to gloabalize these image(-location)s
		// @TODO Perhaps refactor in a function together with the start of 
		// content_generateHTML
		$image_location = 'media/PBNSimple/images/';
		$spade = '<img src="' . $image_location
			. 'spade.png" alt="spade" />';
		$heart = '<img src="' . $image_location
			. 'heart.png" alt="heart" />';
		$diamond = '<img src="' . $image_location
			. 'diamond.png" alt="diamond" />';
		$club = '<img src="' . $image_location . 'club.png" alt="club" />';
		
		$suit_replace[0] = $spade;
		$suit_replace[1] = $heart;
		$suit_replace[2] = $diamond;
		$suit_replace[3] = $club;
		
		$suit_pattern[0] = '/:s:/i';
		$suit_pattern[1] = '/:h:/i';
		$suit_pattern[2] = '/:d:/i';
		$suit_pattern[3] = '/:c:/i';
		
		$row->txt = preg_replace( $suit_pattern, $suit_replace, $row->text );
		
		// $stop = microtime( true );
		// echo '<br />Time in onContentPrepare: ' . ( $stop - $start ) . '<br />';
		// Time in onContentPrepare: 0.0048880577087402
	}


	/**
	 * Here the raw text from the full PBN regex is cut into pieces
	 * and returned in a handsome array
	 * 
	 * @param String $full_pbn : teh result from the large regex
	 * @return String[][] $less_mess : grouping meta-data and deals in one array
	 */
	protected function process_raw_pbn( $full_pbn )
	{
		for ( $i = 0; $i < count( $full_pbn[0] ); $i += 1 )
		{
			// Picking up the full regex match from [0]
			$deals[] = $full_pbn[0][$i];
		}

		foreach ( $deals as $pbn )
		{
			// $pbn contains the full string from the preg_match_all
			preg_match( '/\[Board "(\d+)"\]/', $pbn, $matches );
			$board[] = $matches[1];
		
			preg_match( '/\[Dealer "(\w)"\]/i', $pbn, $matches );
			$dealer[] = $matches[1];
		
			preg_match( '/\[Vulnerable "(\w+)"\]/i', $pbn, $matches );
			$vuln[] = $matches[1];

			preg_match( '/\[Deal "(.*)"\]/i', $pbn, $matches );	
			$deal[] = $matches[0];
		}
		for ( $i = 0; $i < count( $board ); $i += 1 )
		{
			$less_mess[$i][0] = $board[$i];
			$less_mess[$i][1] = $dealer[$i];
			$less_mess[$i][2] = $vuln[$i];
			$less_mess[$i][3] = $deal[$i];
		}
		return $less_mess;
	}

		
	/**
	 * @param String[][] $deal : The cards, NESW - SHDC
	 * @param int $board : The board number - optional
	 * @param String $dealer : The dealer on this board - optional
	 * @param String $vuln : The vulnerability of this board - optional
	 * @return $table : HTML code for the input board
	 */
	protected function content_generateHTML( $deal, $board = 0,
		$dealer = '', $vuln = '' )
	{
		$image_location = 'media/PBNSimple/images/';
		$spade = '<img src="' . $image_location 
			. 'spade.png" alt="spade" />';
		$heart = '<img src="' . $image_location 
			. 'heart.png" alt="heart" />';
		$diamond = '<img src="' . $image_location 
			. 'diamond.png" alt="diamond" />';
		$club = '<img src="' . $image_location . 'club.png" alt="club" />';
		
		if ( $board != 0 )
		{
			$board = 'Spel: ' . $board;
		}
		else
		{
			$board = '';
		}
		if ( $dealer != '' )
		{
			if ( $dealer == 'E' )
			{
				$dealer = 'O';
			}
			if ( $dealer == 'S' )
			{
				$dealer = 'Z';
			}
		}
		switch ( $vuln )
		{
			case 'None':
				$vuln = '-';
				break;
				
			case 'NS':
				$vuln = 'NZ';
				break;
				 
			case 'EW':
				$vuln = 'OW';
				break;
				 
			case 'All':
				$vuln = 'Allen';
				break;
				 
			default:
				$vuln = '';
		}
			
		if ( $dealer != '' && $vuln != '' )
		{
			$vuln_text = $dealer . ' / ' . $vuln;
		}
		else
		{
			$vuln_text = $dealer . $vuln;
		}		
			
		$table = "<table>\n";
		$table .= "<tr><td colspan=\"2\">" . $board . "</td><td>" . $spade;
			$table .= "</td><td>" . $deal[0][0] . "</td><td colspan=\"2\">";
			$table .= "</td></tr>\n";
		$table .= "<tr><td colspan=\"2\">" . $vuln_text . "</td><td>" . $heart;
			$table .= "</td><td>" . $deal[0][1] . "</td><td colspan=\"2\">";
			$table .= "</td></tr>\n"; 
		$table .= "<tr><td colspan=\"2\"></td><td>" . $diamond . "</td><td>";
			$table .= $deal[0][2] . "</td><td colspan=\"2\"></td></tr>\n";
		$table .= "<tr><td colspan=\"2\"></td><td>" . $club . "</td><td>";
			$table .= $deal[0][3] . "</td><td colspan=\"2\"></td></tr>\n";
		
		$table .= "<tr><td>" . $spade . "</td><td>" . $deal[3][0] . "</td>";
			$table .= "<td colspan=\"2\"></td><td>" . $spade . "</td><td>"; 
			$table .= $deal[1][0] . "</td></tr>\n";
		$table .= "<tr><td>" . $heart . "</td><td>" . $deal[3][1]
			. "</td><td colspan=\"2\"></td><td>" . $heart . "</td><td>";
			$table .= $deal[1][1] . "</td></tr>\n";
		$table .= "<tr><td>" . $diamond . "</td><td>" . $deal[3][2] . "</td>";
			$table .= "<td colspan=\"2\"></td><td>" . $diamond . "</td><td>";
			$table .= $deal[1][2] . "</td></tr>\n";
		$table .= "<tr><td>" . $club . "</td><td>" . $deal[3][3] . "</td>";
			$table .= "<td colspan=\"2\"></td><td>" . $club . "</td><td>";
			$table .= $deal[1][3] . "</td></tr>\n";
		
		$table .= "<tr><td colspan=\"2\"></td><td>" . $spade . "</td><td>";
			$table .= $deal[2][0] . "</td><td colspan=\"2\"></td></tr>\n";
		$table .= "<tr><td colspan=\"2\"></td><td>" . $heart . "</td><td>";
			$table .= $deal[2][1] . "</td><td colspan=\"2\"></td></tr>\n";
		$table .= "<tr><td colspan=\"2\"></td><td>" . $diamond . "</td><td>";
			$table .= $deal[2][2] . "</td><td colspan=\"2\"></td></tr>\n";
		$table .= "<tr><td colspan=\"2\"></td><td>" . $club . "</td><td>";
			$table .= $deal[2][3] . "</td><td colspan=\"2\"></td></tr>\n";
		$table .= "</table>";
		
		return $table;
	}


	/**
	 * Regenerates a bare PBN string from a two-dimensional
	 * array holding the hands
	 *
	 * @param String[][] $this_deal : a two-dimensional array
	 * holding the hands of the players
	 * @return String $pbn_string : The concatenated string, bare PBN
	 */
	protected function regenerate_pbn_string( $this_deal )
	{
		for ( $i = 0; $i < 4; $i += 1 )
		{
		
			for ( $j = 0; $j < 4; $j += 1 )
			{
				$pbn_string .= $this_deal[$i][$j];
				if ( $j % 4 != 3 )
				{
					$pbn_string .= '.';
				}
				else
				{
					$pbn_string .= ' ';
				}
			}
		}
		$pbn_string = trim( $pbn_string );

		return $pbn_string;
	}


	/**
	 * The PBN string doesn't nees to start with the North hand. 
	 * This unction determines the startng hand and returns it.
	 * 
	 * @param String $pbn : The (stripped) PBN
	 * @return String $first_hand : The hand that is determined to be the 
	 * first in the PBN string
	 */
	protected function get_first_hand( $pbn )
	{
		preg_match( '/[NESW]/', $pbn, $matches );
		return $matches[1];
	}


	/**
	 * This function revolves the already processed $pbn array in case the
	 * first hand happens not to be North. Returns a similar array where
	 * North is the first hand ($temp[0])
	 * 
	 * @param int[][] $pbn : the array woth the cards where North is not in
	 * 0th position
	 * @param String $first_hand : the hand that actual is in first position
	 * in the $pbn array
	 * @return int[][] a similar array where North is in 0th position
	 */
	protected function turn_hand( $pbn, $first_hand )
	{
		switch ( $first_hand )
		{
			case 'E':
			case 'O':
				for ( $i = 0; $i < 4; $i += 1 )
				{
					for ($j = 0; $j < 4; $j += 1 )
					{
						$temp[(($i + 1) % 4)][$j] = $pbn[$i][$j];
					}
				}
				break;
				
			case 'S':
			case 'Z':
				for ( $i = 0; $i < 4; $i += 1 )
				{
					for ($j = 0; $j < 4; $j += 1 )
					{
						$temp[(($i + 2) % 4)][$j] = $pbn[$i][$j];
					}
				}
				break;
				
			case 'W':
				for ( $i = 0; $i < 4; $i += 1 )
				{
					for ($j = 0; $j < 4; $j += 1 )
					{
						$temp[(($i + 3) % 4)][$j] = $pbn[$i][$j];
					}
				}
				break;
				
			case 'N':
			default:
				// Shouldn't happen. Just in case ...
				for ( $i = 0; $i < 4; $i += 1 )
				{
					for ($j = 0; $j < 4; $j += 1 )
					{
						$temp[$i][$j] = $pbn[$i][$j];
					}
				}			
		}
		return $temp;
	}

	
	/**
	 * This function takes the raw pbn and processes it into a two-
	 * dimensional array with [0] as North and [x][0] as spades
	 * 
	 * @param String[] $pbn : The raw PBN like [Deal "N: (...) "]
	 * @return Mixed[][][] $deals : three-dimensional array with 
	 * [x][0] North and [x][y][0] spades
	 */
	protected function process_pbn( $pbn )
	{
		if ( is_string( $pbn ) )
		{
			$pbn[0] = $pbn;
		}
		unset( $deal );
		foreach ( $pbn as $pbn_array )
		{
			foreach ( $pbn_array as $full_deal )
			{	
				$first_hand = $this->get_first_hand( $full_deal );
				$hand = trim( $full_deal );
				preg_match( '/\[Deal "[NESW]:(.*)"\]/', $hand, $bare_pbn );

				$hands = explode( " ", $bare_pbn[1] );
		
				for ( $i = 0; $i < 4; $i += 1 )
				{
					$deal[$i] = explode( ".", $hands[$i] );
				}

				if ( $first_hand != 'N' )
				{
					$deal = $this->turn_hand( $deal, $first_hand );
				}
				if ( $deal )
				{
					$deals[] = $deal;
				}
			}
		}
		return $deals;
	}
}
?>
