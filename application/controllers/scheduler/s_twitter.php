<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Twitter Scheduler Controller
 *
 * PHP version 5
 * LICENSE: This source file is subject to LGPL license
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/lesser.html
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi - http://source.ushahididev.com
 * @module     Twitter Controller
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license    http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License (LGPL)
*/

class S_Twitter_Controller extends Controller {

	// Cache instance
	protected $cache;
	
	public function __construct()
	{
		parent::__construct();
		
		// Load cache
		$this->cache = new Cache;
		
		// *************************************
		// Create A 10 Minute RETRIEVE LOCK
		// This lock is released at the end of execution
		// Or expires automatically
		$twitter_lock = $this->cache->get("twitter_lock");
		if ( ! $twitter_lock)
		{
			// Lock doesn't exist
			$timestamp = time();
			$this->cache->set("twitter_lock", $timestamp, array("alerts"), 900);
		}
		else
		{
			// Lock Exists - End
			exit("Other process is running - waiting 10 minutes!");
		}
		// *************************************
	}
	
	public function __destruct()
	{
		$this->cache->delete("twitter_lock");
	}

	public function index()
	{
		// Grabbing tweets requires cURL so we will check for that here.
		if (!function_exists('curl_exec'))
		{
			throw new Kohana_Exception('twitter.cURL_not_installed');
			return false;
		}

		// Retrieve Current Settings
		$settings = ORM::factory('settings', 1);

		// Retrieve Last Stored Twitter ID
		$last_tweet_id = "";
		$tweets = ORM::factory('message')
			->with('reporter')
			->where('service_id', '3')
			->orderby('service_messageid','desc')
			->find();
		if ($tweets->loaded == true)
		{
			$last_tweet_id = "&since_id=" . $tweets->service_messageid;
		}

		//Perform Hashtag Search
		// twitter_hashtags is now used verbatim as a twitter search query
		$search_query = trim($settings->twitter_hashtags);
		if($search_query) {
			$page = 1;
			$have_results = TRUE; //just starting us off as true, although there may be no results
			while($have_results == TRUE AND $page <= 2)
			{ //This loop is for pagination of rss results
				$twitter_url = 'http://search.twitter.com/search.json?q='.urlencode($search_query).'&rpp=100&page='.$page.$last_tweet_id;
				$curl_handle = curl_init();
				curl_setopt($curl_handle,CURLOPT_URL,$twitter_url);
				curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,4); //Since Twitter is down a lot, set timeout to 4 secs
				curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1); //Set curl to store data in variable instead of print
				$buffer = curl_exec($curl_handle);
				curl_close($curl_handle);
				$have_results = $this->add_hash_tweets($buffer); //if FALSE, we will drop out of the loop
				$page++;
			}
		}
	}

	/**
	* Adds hash tweets in JSON format to the database and saves the sender as a new
	* Reporter if they don't already exist
    * @param string $data - Twitter JSON results
    */
	private function add_hash_tweets($data)
	{
		$services = new Service_Model();
		$service = $services->where('service_name', 'Twitter')->find();
	   	if (!$service) {
 			return;
		}

		// HACK: Make twitter IDs be strings so 32bit php doesn't choke on them - Nigel McNie
		$data = preg_replace('/"id":(\d+)/', '"id":"$1"', $data);

		$tweets = json_decode($data, false);
		if (!$tweets) {
			return;
		}
		if (isset($tweets->{'error'})) {
			return;
		}

		$tweet_results = $tweets->{'results'};

		foreach($tweet_results as $tweet) {
			// Skip over duplicate tweets
			$tweet_hash = $this->tweet_hash($tweet->text);
			if($this->is_tweet_registered($tweet_hash)) continue;

			$reporter = ORM::factory('reporter')
				->where('service_id', $service->id)
				->where('service_account', $tweet->{'from_user'})
				->find();

			if (!$reporter->loaded)
			{
	    		// get default reporter level (Untrusted)
				$level = ORM::factory('level')
					->where('level_weight', 0)
					->find();

				$reporter->service_id	   = $service->id;
				$reporter->level_id			= $level->id;
				$reporter->service_userid   = null;
				$reporter->service_account  = $tweet->{'from_user'};
				$reporter->reporter_first   = null;
				$reporter->reporter_last	= null;
				$reporter->reporter_email   = null;
				$reporter->reporter_phone   = null;
				$reporter->reporter_ip	  = null;
				$reporter->reporter_date	= date('Y-m-d');
				$reporter->save();
			}

			if ($reporter->level_id > 1 &&
				count(ORM::factory('message')->where('service_messageid', $tweet->{'id'})
									   ->find_all()) == 0) {
				// Save Tweet as Message
				$message = new Message_Model();
				$message->parent_id = 0;
				$message->incident_id = 0;
				$message->user_id = 0;
				$message->reporter_id = $reporter->id;
				$message->message_from = $tweet->{'from_user'};
				$message->message_to = null;
				$message->message = $tweet->{'text'};
				$message->message_type = 1; // Inbox
				$tweet_date = date("Y-m-d H:i:s",strtotime($tweet->{'created_at'}));
				$message->message_date = $tweet_date;
				$message->service_messageid = $tweet->{'id'};
				$message->save();

				// Mark this tweet as received for the duplicate checker
				$this->register_tweet($message->id, $tweet_hash);
				
				// Action::message_twitter_add - Twitter Message Received!
                Event::run('ushahidi_action.message_twitter_add', $message);
			}
			
			// Auto-Create A Report if Reporter is Trusted
			$reporter_weight = $reporter->level->level_weight;
			$reporter_location = $reporter->location;
			if ($reporter_weight > 0 AND $reporter_location)
			{
				$incident_title = text::limit_chars($message->message, 50, "...", false);
				
				// Create Incident
				$incident = new Incident_Model();
				$incident->location_id = $reporter_location->id;
				$incident->incident_title = $incident_title;
				$incident->incident_description = $message->message;
				$incident->incident_date = $tweet_date;
				$incident->incident_dateadd = date("Y-m-d H:i:s",time());
				$incident->incident_active = 1;
				if ($reporter_weight == 2)
				{
					$incident->incident_verified = 1;
				}
				$incident->save();

				// Update Message with Incident ID
				$message->incident_id = $incident->id;
				$message->save();

				// Save Incident Category
				$trusted_categories = ORM::factory("category")
					->where("category_trusted", 1)
					->find();
				if ($trusted_categories->loaded)
				{
					$incident_category = new Incident_Category_Model();
					$incident_category->incident_id = $incident->id;
					$incident_category->category_id = $trusted_categories->id;
					$incident_category->save();
				}
			}
		}
	}

	/**
	 * Returns true if the given tweet has been been received
	 */
	private function is_tweet_registered($hash)
	{
		$hash_a = substr($hash,0,16);
		$hash_b = substr($hash,16);

		return count(ORM::factory('message_hash')
			->where('hash_a', "X'$hash_a'", false)
			->where('hash_b', "X'$hash_b'", false)
			->find_all()) > 0;
	}

	/**
	 * Register this tweet as having been received
	 */
	private function register_tweet($message_id, $hash)
	{
		$hash_a = substr($hash,0,16);
		$hash_b = substr($hash,16);

		// Raw query used so we can save directly as hex.
		$message_id = (int)$message_id;
		Database::instance()->query("INSERT INTO `message_hash` SET message_id = $message_id,
			hash_a = X'$hash_a', hash_b = X'$hash_b'");
	}

	/**
	 * Generate a hash of the given tweet text, for checking if it's a duplicate
	 */
	private function tweet_hash($text)
	{
		$text = preg_replace('/RT @[^: ]+:?/i','', $text);
		$text = preg_replace('/#[^ #]+/', ' ', $text);
		$text = preg_replace('/ +/', ' ', $text);
		$text = strtolower(trim($text));
		return md5($text);
	}
}
