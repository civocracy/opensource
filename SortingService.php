<?php


namespace AppBundle\Service;

use AppBundle\Entity\Community;
use Doctrine\ORM\EntityManager;
use AppBundle\Service\RatingService;
use AppBundle\Service\LocaleService;
use AppBundle\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use \Doctrine\Common\Util\ClassUtils;

/**
 *
 *
 * Class SortingService
 * @package AppBundle\Service
 */
class SortingService
{

	/**
	 * @var \Doctrine\ORM\EntityManager
	 */
	protected $em;

	/**
	 * @var TokenStorage
	 */
	protected $tokenStorage;
	/**
	 * @var LocaleService
	 */
	protected $localeService;

	/**
	 * @var RatingService
	 */
	protected $ratingService;

	/**
	 * @var TrackingService
	 */
	protected $trackingService;


	const CATEGORIE3DAYS = 2;
	const CATEGORIE2DAYS = 14;
	const CATEGORIE1DAYS = 60;

	const BADGETOPDOWNINFLUENCE = 2;
	const BADGETOPDOWNADD = 20;
	const BADGEIMPACTINFLUENCE = 2.5;
	const BADGEIMPACTADD = 50;
	const COMMENTSINFLUENCE = 1;

	// "New" algorithm
	const GLOBALRELEVANCYINFLUENCE = 0.025;
	const GLOBALRELEVANCYMAXDAYS = 30;
	const DISTANCEINFLUENCE = 2;

	const SIMILARNAMETHRESHOLD = 90;

	public function __construct(EntityManager $em, TokenStorage $tokenStorage, $localeService, $ratingService, $trackingService)
	{
		$this->em = $em;
		$this->tokenStorage = $tokenStorage;
		$this->localeService = $localeService;
		$this->ratingService = $ratingService;
		$this->trackingService = $trackingService;
	}

	/**
	 * Compute relevancy and sort content
	 * This function is generic for all content entities
	 */
	public function sortContentsByRelevancy($contents, $order = "DESC")
	{
		if ($contents == array()) {
			return array();
		}

		usort($contents, "AppBundle\Service\SortingService::compareContentsRelevancy");

		if ($order == "ASC") {
			array_reverse($contents);
		}

		return $contents;
	}

	static function compareContentsRelevancy($content, $contentToCompare)
	{
		$criterionComment = $content->getGlobalRelevancy();
		$criterionCommentToCompare = $contentToCompare->getGlobalRelevancy();

		if ($criterionComment == $criterionCommentToCompare) {
			return 0;
		}

		return ($criterionComment > $criterionCommentToCompare) ? -1 : 1;
	}

	static function compareContentsPoints($content, $contentToCompare)
	{
		$criterionComment = $content->getPoints();
		$criterionCommentToCompare = $contentToCompare->getPoints();

		if ($criterionComment == $criterionCommentToCompare) {
			return 0;
		}

		return ($criterionComment > $criterionCommentToCompare) ? -1 : 1;
	}


	/**
	 * Compute relevancy and sort contents by the "new" fetch method
	 * This function is generic for all content entities, issues and communities
	 */
	public function sortContentsNew($contents, $community, $locale, $order = "DESC", $self)
	{
		if ($contents == array()) {
			return array();
		}

		if ($self instanceof User) {
			$contents = $this->trackingService->computeTrackingCount($contents, $self, 'seen');
		}

		foreach ($contents as $content) {

			$seenPoint = 0;
			$qualityPoint = 0;

			// SEEN
			$countSeen = $content->getCount('seen');
			switch (true) {
				case ($countSeen == 0): $seenPoint = 9; break;
				case ($countSeen < 0): $seenPoint = 1; break;
				case ($countSeen <= 3): $seenPoint = 8; break;
				case ($countSeen <= 10): $seenPoint = 7; break;
				case ($countSeen > 10):  $seenPoint = 6; break;
				default: $seenPoint = 2;
			}

			// QUALITY
			$qualityPoint = $this->getContentQuality($content, $community);

			$seenPoint = min(max($seenPoint, 0), 9);
			$qualityPoint = min(max($qualityPoint, 0), 999);

			$content->setPoints($seenPoint * 1000 + $qualityPoint);
		}

		usort($contents, "AppBundle\Service\SortingService::compareContentsPoints");

		if ($order == "ASC") {
			$contents = array_reverse($contents);
		}

		return $contents;
	}

	/**
	 * Evaluate the relevance on the content, based on reaction ratio, date and other criteria
	 */
	public function getContentQuality($content, $community)
	{
		$now = new \DateTime();

		$contentDays = ($now->getTimestamp() - $content->getDate()->getTimestamp()) / 86400;
		$daysPoint = 356 - $contentDays;

		$globalRelevancy = $content->getGlobalRelevancy();

		// POPULARITY - Upvotes

		// Special case: global relevancy nerf for issues and communities
		if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Issue') {
			$globalRelevancy = ceil($globalRelevancy/10);
		}

		// Special case: TopDown Badge for comments
		if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Comment') {
			if ($content->getBadgeTopDown() == 1) {
				$globalRelevancy *= self::BADGETOPDOWNINFLUENCE;
				$globalRelevancy += self::BADGETOPDOWNADD;
			}
		}


		// Special case: impact badge for comments
		if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Comment') {
			if ($content->getBadgeImpact() == 1) {
				$globalRelevancy *= self::BADGEIMPACTINFLUENCE;
				$globalRelevancy += self::BADGEIMPACTADD;
			}
		}

		// special case : number of comments for comments
		if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Comment') {
			foreach ($content->getComments() as $comment) {
				$globalRelevancy += self::COMMENTSINFLUENCE;
			}
		}

		// DISTANCE - Compute the distance factor
		if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Proposition' || ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Issue') {

			$contentCommunity = $content->getCommunity();

			$distancePoint = 0;
			if ($contentCommunity == $community) {
				$distancePoint = 9;
			} else if ($contentCommunity != null && $community != null) {
				$levelDifference = $contentCommunity->getLevel() - $community->getLevel();

				$distancePoint = min(round((80 - $levelDifference) / 10), 8);

			}

			$globalRelevancy  += $distancePoint * self::DISTANCEINFLUENCE;
		}

		$globalRelevancyPoints = (1 / (-self::GLOBALRELEVANCYINFLUENCE * (round($globalRelevancy) + (1/(self::GLOBALRELEVANCYMAXDAYS * self::GLOBALRELEVANCYINFLUENCE) ))) ) + self::GLOBALRELEVANCYMAXDAYS;

		$quality = round($daysPoint - $globalRelevancyPoints);

		return $quality;

	}

	/**
	 * Compute relevancy and sort contents by relevancy, date, community and locale
	 * This function is generic for all content entities, issues and communities
	 */
	public function sortContentsBest($contents, $community, $locale, $order = "DESC")
	{
		if ($contents == array()) {
			return array();
		}

		$now = new \DateTime();

		// Is issue closed?
		$closed = false;
		if (method_exists(array_values($contents)[0], 'getIssue') && ClassUtils::getRealClass(get_class(array_values($contents)[0])) != 'AppBundle\Entity\Proposition') { // Contents
			if (array_values($contents)[0]->getIssue()->getDateEnd() < $now) {
				$closed = true;
			}
		}

		foreach ($contents as $content) {

			$distancePoint = 0;
			$datePoint = 0;

			if (method_exists($content, 'getClusterGlobalRelevancyScore')) {
				$upvotes = $content->getClusterGlobalRelevancyScore();
			} else if (method_exists($content, 'getGlobalRelevancyScore')) {
				$upvotes = $content->getGlobalRelevancyScore();
			} else {
				$upvotes = $content->getGlobalRelevancy();
			}


			// DISTANCE - Compute the distance factor
			if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Proposition' || ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Issue') {

				$contentCommunity = $content->getCommunity();

				if ($contentCommunity == $community) {
					$distancePoint = 9;
				} else if ($contentCommunity != null && $community != null) {
					$levelDifference = $contentCommunity->getLevel() - $community->getLevel();

					$distancePoint = min(round((80 - $levelDifference) / 10), 8);
				}
			}

			// Special case -- for comments, the distance factor is "is comment a root?"
			if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Comment') {
				if ($content->getRoot() == null) {
					$distancePoint = 9;
				}
			}

			// Special case -- for followings, the distance factor is "is person admin?"
			if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\CommunityFollowing' ||
				ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\IssueFollowing') {

				if ($content->getAdminLevel() >= 1) {
					$distancePoint = 9;
				}
			}

			// DATE - Compute the date factor
			if (ClassUtils::getRealClass(get_class($content)) != 'AppBundle\Entity\Community') {

				// Special case: impact badge for comments
				if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Comment') {
					if ($content->getBadgeImpact() == 1) {
						if ($closed) {
							$datePoint = 9; // special case: always first comments
						} else {
							$upvotes *= self::BADGEIMPACTINFLUENCE;
							$upvotes += self::BADGEIMPACTADD;
						}
					}
				}

				// Special case: issues
				if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Issue') {
					if ($content->getOfficial()) {
						$datePoint += 3;
					}
					// open?
					if ($content->getDateEnd() > $now) {
						$datePoint += 4;
					}
				}

			} else { // Special case for communities (isActive)
				$datePoint = ($content->getIsActive()) ?  9 : 0;
			}

			// POPULARITY - Upvotes

			// Special case: TopDown Badge for comments
			if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Comment') {
				if ($content->getBadgeTopDown() == 1) {
					$upvotes *= self::BADGETOPDOWNINFLUENCE;
					$upvotes += self::BADGETOPDOWNADD;
				}
			}

			// special case : number of comments for comments
			if (ClassUtils::getRealClass(get_class($content)) == 'AppBundle\Entity\Comment') {
				foreach ($content->getComments() as $comment) {
					$upvotes += self::COMMENTSINFLUENCE;
				}
			}

			$distancePoint = min(max($distancePoint, 0), 9);
			$datePoint = min(max($datePoint, 0), 9);
			$upvotes = round(min(max($upvotes, 0), 999));

			$content->setPoints($distancePoint * 10000 + $datePoint * 1000 + $upvotes);
		}

		usort($contents, "AppBundle\Service\SortingService::compareContentsPoints");

		if ($order == "ASC") {
			$contents = array_reverse($contents);
		}

		return $contents;
	}

	/**
	 * This function puts first the exact matches when searching by nameLike and returns the contents
	 */
	public function nameLikePerfectMatchesSorting($contents, $nameLike) {
		$nameLike = strtolower(preg_replace('/\s+/', '', trim($nameLike)));

		$contentsFirst = array();
		foreach ($contents as $key => $content) {

			$class = ClassUtils::getRealClass(get_class($content));

			switch ($class) {
				case 'AppBundle\Entity\Community':
					$fooCommunity = new Community();
					$cleanNameLike = $fooCommunity->cleanURLencoder($nameLike);

					similar_text(strtolower($content->getURL()), $cleanNameLike, $percent1);
					similar_text(strtolower($content->getName()), $nameLike, $percent2);

					if ($percent1 >= self::SIMILARNAMETHRESHOLD || $percent2 >= self::SIMILARNAMETHRESHOLD) {
						$contentsFirst[] = $content;
						unset($contents[$key]);
					}
					break;
				case 'AppBundle\Entity\Issue':
					similar_text(strtolower($content->getTag()), $nameLike, $percent1);
					similar_text(strtolower($content->getTitle()), $nameLike, $percent2);

					if ($percent1 >= self::SIMILARNAMETHRESHOLD || $percent2 >= self::SIMILARNAMETHRESHOLD) {
						$contentsFirst[] = $content;
						unset($contents[$key]);
					}
					break;
				case 'AppBundle\Entity\User':
					$percent1 = similar_text(strtolower($content->getUsername()), $nameLike, $percent1);

					if ($percent1 >= self::SIMILARNAMETHRESHOLD) {
						$contentsFirst[] = $content;
						unset($contents[$key]);
					}
					break;
				default:
					similar_text(strtolower($content->getName()), $nameLike, $percent1);

					if ($percent1 >= self::SIMILARNAMETHRESHOLD) {
						$contentsFirst[] = $content;
						unset($contents[$key]);
					}
					break;
			}

		}

		return array_merge(array_values($contentsFirst), $contents);
	}


	public function getByAfter($class, $filters, $orderBy, $limit, $offset) {

		$query = $this->em->getRepository($class)->createQueryBuilder('c');

		foreach ($filters as $criteria => $value) {
			if (is_string($value)) {
				$value = '\'' . $value . '\'';
			}

			if (is_array($value)) { // multi-criteria case
				$andWhereClause = '';
				foreach ($value as $aValue) {
					if ($andWhereClause != '') {
						$andWhereClause .= ' OR ';
					}

					$comparison = '=';
					if ($criteria == 'date') { // Could be evolved to a value comparison
						$comparison = '>';
					}
					if ($aValue === null) {
						$comparison = ' IS NULL ';
					}

					$andWhereClause .= 'c.' . $criteria . $comparison . $aValue;
				}
			} else {
				$comparison = '=';
				if ($criteria == 'date') {
					$comparison = '>';
				}
				if ($value === null) {
					$comparison = ' IS NULL ';
				}

				$query->andWhere('c.' . $criteria . $comparison . $value);
			}

		}

		foreach ($orderBy as $criteria => $order) {
			$query->addOrderBy('c.' . $criteria, $order);
		}

		$query->setMaxResults($limit)
				->setFirstResult($offset);

		return $query->getQuery()->getResult();
	}

	public function getByNameLike($class, $nameLike, $filters = array(), $orderBy = array(), $limit = 20, $offset = 0) {

		if ($class == 'AppBundle\Entity\Comment' || $class == 'AppBundle\Entity\Proposition' || $class == 'AppBundle\Entity\LanguageEntity') { // Text => Keep spaces
			$nameLike = strtolower(trim($nameLike));
		} else {
			$nameLike = strtolower(preg_replace('/\s+/', '', trim($nameLike)));
		}

		switch ($class) {
			case 'AppBundle\Entity\Comment':
				$query = $this->em->getRepository($class)->createQueryBuilder('c')
						->where('LOWER(c.content) LIKE :content OR LOWER(c.title) LIKE :content')
						->setParameter('content', '%' . $nameLike . '%');
				break;
			case 'AppBundle\Entity\Community':
				$fooCommunity = new Community();
				$cleanNameLike = $fooCommunity->cleanURLencoder($nameLike);

				$query = $this->em->getRepository($class)->createQueryBuilder('c')
						->where('c.url LIKE :cleanName OR LOWER(c.name) LIKE :name OR LOWER(c.name) LIKE :nameWithSpace AND c.status != \'duplicate\'  AND c.status != \'deleted\' AND c.status != \'homonym\'')
						->setParameter('cleanName', $cleanNameLike . '%')
						->setParameter('name', $nameLike . '%')
						->setParameter('nameWithSpace', '% ' . $nameLike . '%');
				break;
			case 'AppBundle\Entity\Issue':
				$query = $this->em->getRepository($class)->createQueryBuilder('c')
						->where('(LOWER(c.tag) LIKE :name OR LOWER(c.title) LIKE :name)')
						->setParameter('name', '%' . $nameLike . '%');
				break;
			case 'AppBundle\Entity\Prospect':
				$fooCommunity = new Community();
				$cleanNameLike = $fooCommunity->cleanURLencoder($nameLike);

				$query = $this->em->getRepository($class)->createQueryBuilder('c')
						->where('LOWER(c.title) LIKE :name OR LOWER(c.communityName) LIKE :name OR LOWER(c.email) LIKE :cleanName OR LOWER(c.email) LIKE :name')
						->setParameter('cleanName', '%' . $cleanNameLike . '%')
						->setParameter('name', '%' . $nameLike . '%');
				break;
			case 'AppBundle\Entity\Proposition':

				$query = $this->em->getRepository($class)->createQueryBuilder('c')
						->where('LOWER(c.content2) LIKE :content OR LOWER(c.content1) LIKE :content')
						->setParameter('content', '%' . $nameLike . '%');
				break;
			case 'AppBundle\Entity\User':
				$query = $this->em->getRepository($class)->createQueryBuilder('c')
						->where('LOWER(c.username) LIKE :name OR LOWER(c.firstName) LIKE :name OR LOWER(c.lastName) LIKE :name OR LOWER(CONCAT(c.firstName, c.lastName)) LIKE :name')
						->setParameter('name', $nameLike . '%');
				break;
			default:
				$query = $this->em->getRepository($class)->createQueryBuilder('c')
						->where('LOWER(c.name) LIKE :name')
						->setParameter('name', '%' . $nameLike . '%');
		}

		foreach ($filters as $criteria => $value) {
			if (is_string($value)) {
				$value = '\'' . $value . '\'';
			}

			if (is_array($value)) { // multi-criteria case
				$andWhereClause = '';

				foreach ($value as $aValue) {
					if ($andWhereClause != '') {
						$andWhereClause .= ' OR ';
					}

					if ($aValue === null) {
						$andWhereClause .= 'c.' . $criteria . ' IS NULL ';
					} else {
						if (is_string($aValue)) {
							$aValue = '\'' . $aValue . '\'';
						}

						$andWhereClause .= 'c.' . $criteria . '=' . $aValue;
					}
				}
			} else {
				$andWhereClause = '';
				if ($value === null) {
					$andWhereClause .= 'c.' . $criteria . ' IS NULL ';
				} else {
					$andWhereClause .= 'c.' . $criteria . '=' . $value;
				}
			}

			$query->andWhere($andWhereClause);
		}

		foreach ($orderBy as $criteria => $order) {
			$query->addOrderBy('c.' . $criteria, $order);
		}

		$query->setMaxResults($limit)
				->setFirstResult($offset);

		return $query->getQuery()->getResult();
	}

	/**
	 * Returns if the entity in parameter has an exact double
	 *
	 * @param $entity
	 * @return bool
	 */
	public function isAlreadyExisting($entity) {
		$class = ClassUtils::getRealClass(get_class($entity));
		$filters = array();

		if (method_exists($entity, 'getTitle')) {
			$filters['title'] = $entity->getTitle();
		}
		if (method_exists($entity, 'getURL')) {
			$filters['url'] = $entity->getURL();
		}
		if (method_exists($entity, 'getSummary')) {
			$filters['summary'] = $entity->getSummary();
		}
		if (method_exists($entity, 'getDescription')) {
			$filters['description'] = $entity->getDescription();
		}
		if (method_exists($entity, 'getContent') && is_string($entity->getContent()) &&
				$class != 'AppBundle\Entity\Proposition') {
			$filters['content'] = $entity->getContent();
		}
		if (method_exists($entity, 'getContent2')) {
			$filters['content2'] = $entity->getContent2();
		}

		if (method_exists($entity, 'getUser')) {
			$filters['user'] = $entity->getUser();
		}
		if (method_exists($entity, 'getIssue')) {
			$filters['issue'] = $entity->getIssue();
		}
		if (method_exists($entity, 'getCommunity')) {
			$filters['community'] = $entity->getCommunity();
		}

		if (method_exists($entity, 'getDateBegin') && $class == 'AppBundle\Entity\Event') {
			$filters['dateBegin'] = $entity->getDateBegin();
		}

		$otherEntities = $this->em->getRepository($class)->findBy($filters, array(), 1, 0);

		return ($otherEntities !== array());
	}

} 