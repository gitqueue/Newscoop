<?php
/**
 * @package Newscoop\NewscoopBundle
 * @author Rafał Muszyński <rafal.muszynski@sourcefabric.org>
 * @copyright 2014 Sourcefabric z.ú.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace Newscoop\NewscoopBundle\Entity\Repository;

use Gedmo\Tree\Entity\Repository\NestedTreeRepository;
use Doctrine\ORM\Query;
use Newscoop\NewscoopBundle\Entity\Topic;
use Closure;
use Gedmo\Translatable\TranslatableListener;

class TopicRepository extends NestedTreeRepository
{
    public $onChildrenQuery;

    /**
     * Get all topics
     *
     * @return Doctrine\ORM\Query
     */
    public function getTopics()
    {
        $meta = $this->getClassMetadata();
        $config = $this->listener->getConfiguration($this->_em, $meta->name);

        $queryBuilder = $this
            ->getQueryBuilder('t')
            ->select('t')
            ->from($config['useObjectClass'], 't');

        $countQueryBuilder = $this
            ->getQueryBuilder('t')
            ->select('count(t)')
            ->from($config['useObjectClass'], 't');

        $topicsCount = $countQueryBuilder->getQuery()->getSingleScalarResult();
        $query = $this->setTranslatableHint($queryBuilder->getQuery());
        $query->setHint('knp_paginator.count', $topicsCount);

        return $query;
    }

    /**
     * Get all parent choices
     *
     * @param Topic|null $node Topic object
     *
     * @return array
     */
    public function findAllParentChoices(Topic $node = null)
    {
        $dql = "SELECT c FROM {$this->_entityName} c";
        if (!is_null($node)) {
            $subSelect = "SELECT n FROM {$this->_entityName} n";
            $subSelect .= ' WHERE n.root = '.$node->getRoot();
            $subSelect .= ' AND n.lft BETWEEN '.$node->getLeft().' AND '.$node->getRight();

            $dql .= " WHERE c.id NOT IN ({$subSelect})";
        }
        $q = $this->_em->createQuery($dql);
        $q->setHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
        );
        $nodes = $q->getArrayResult();
        $indexed = array();
        foreach ($nodes as $node) {
            $indexed[$node['id']] = $node['title'];
        }

        return $indexed;
    }

    /**
     * Will do reordering based on current translations
     */
    public function childrenQuery($node = null, $direct = false, $sortByField = null, $direction = 'ASC', $include = false)
    {
        $q = parent::childrenQuery($node, $direct, $sortByField, $direction, $include);
        if ($this->onChildrenQuery instanceof Closure) {
            $c = &$this->onChildrenQuery;
            $c($q);
        }

        return $q;
    }

    public function getTopicsQuery($direction = 'ASC')
    {
        $meta = $this->getClassMetadata();
        $config = $this->listener->getConfiguration($this->_em, $meta->name);

        return $this
            ->getQueryBuilder()
            ->select('node')
            ->from($config['useObjectClass'], 'node')
            ->orderBy('node.root, node.lft', $direction)
            ->getQuery();
    }

    /**
     * Gets the single topic's query by id
     *
     * @param int    $id     Topic id
     * @param string $locale Language code
     *
     * @return Query $query Query object
     */
    public function getSingleTopicQuery($id, $locale = null)
    {
        $meta = $this->getClassMetadata();
        $config = $this->listener->getConfiguration($this->_em, $meta->name);

        $queryBuilder = $this->getQueryBuilder()
            ->select('t')
            ->from($config['useObjectClass'], 't')
            ->where('t.id = :id')
            ->setParameter('id', $id);

        $query = $queryBuilder->getQuery();

        return $this->setTranslatableHint($query, $locale);
    }

    /**
     * Get topics query and set translatable hints
     *
     * @param Query $query Query object
     */
    public function getTranslatableTopics($locale, $order = 'asc')
    {
        $query = $this
            ->getQueryBuilder()
            ->select('node', 't')
            ->from('Newscoop\NewscoopBundle\Entity\Topic', 'node')
            ->leftJoin('node.translations', 't')
            ->where("t.field = 'title'");

        $query = $query
            ->orderBy('node.root, node.lft', $order)
            ->getQuery();

        return $this->setTranslatableHint($query, $locale);
    }

    /**
     * Add hints to the query
     *
     * @param Query       $query  Query
     * @param string|null $locale Lecale to which fallback
     *
     * @return Query
     */
    public function setTranslatableHint(Query $query, $locale = null)
    {
        $query->setHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
        );
        $query->setHint(
            TranslatableListener::HINT_INNER_JOIN,
            false
        );
        $query->setHint(
            TranslatableListener::HINT_TRANSLATABLE_LOCALE,
            $locale
        );
        $query->setHint(
            TranslatableListener::HINT_FALLBACK,
            true
        );

        return $query;
    }

    /**
     * Get all articles for given topic by topic id and language code
     *
     * @param int     $topicId         Topic id
     * @param string  $languageCode    Language code
     * @param boolean $defaultFallback Sets the language of the topic to the default one
     *
     * @return Query
     */
    public function getArticlesQueryByTopicIdAndLanguage($topicId, $languageCode, $defaultFallback = false)
    {
        $query = $this
            ->getQueryBuilder()
            ->select('node', 't')
            ->from('Newscoop\NewscoopBundle\Entity\Topic', 'node')
            ->leftJoin('node.translations', 't')
            ->where("t.field = 'title'")
            ->andWhere('node.id = :id')
            ->setParameter('id', $topicId);

        if ($defaultFallback) {
            return $this->setTranslatableHint($query->getQuery(), $languageCode);
        }

        $query->andWhere('t.locale = :locale')
            ->setParameter('locale', $languageCode);

        return $query->getQuery();
    }

    /**
     * Search topic by given query
     *
     * @param string $query
     * @param array  $sort
     *
     * @return Query
     */
    public function searchTopics($query, $sort = array(), $limit = null)
    {
        $meta = $this->getClassMetadata();
        $config = $this->listener->getConfiguration($this->_em, $meta->name);
        $qb = $this->getQueryBuilder()
            ->select('t', 'tt')
            ->from($config['useObjectClass'], 't')
            ->leftJoin('t.translations', 'tt')
            ->where("tt.field = 'title'");

        $orX = $qb->expr()->orx();

        $orX->add($qb->expr()->like('t.title', $qb->expr()->literal("%{$query}%")));
        $orX->add($qb->expr()->like('tt.content', $qb->expr()->literal("%{$query}%")));
        $qb->andWhere($orX);

        if ((!empty($sort)) && is_array($sort)) {
            foreach ($sort as $sortColumn => $sortDir) {
                $qb->addOrderBy('t.'.$sortColumn, $sortDir);
            }
        }

        if (!is_null($limit)) {
            $qb->setMaxResults($limit);
        }

        return $this->setTranslatableHint($qb->getQuery());
    }

    /**
     * Find topic options
     *
     * @return array
     */
    public function findOptions()
    {
        $query = $this->createQueryBuilder('t')
            ->select('t.id, t.title as name')
            ->orderBy('t.title')
            ->getQuery();

        $options = array();
        foreach ($query->getResult() as $row) {
            $options[$row['id']] = $row['name'];
        }

        return $options;
    }

    /**
     * Gets topic's path
     *
     * @param Topic $topic Topic
     *
     * @return string Path of the topic
     */
    public function getReadablePath(Topic $topic, $locale = null)
    {
        $pathQuery = $this->getPathQuery($topic);
        $this->setTranslatableHint($pathQuery, $locale);
        $path = $pathQuery->getArrayResult();
        $pathStr = '';
        foreach ($path as $element) {
            $pathStr = $pathStr . ' / ' . $element['title'];
        }

        return $pathStr;
    }

    /**
     * Get Topics for Article
     *
     * Returns all the associated Topics to an Article.
     *
     * @param int    $articleNr    Article number
     * @param string $languageCode Language code in format "en" for example.
     * @param string $order        Order of the topics, default ascending
     *
     * @return Doctrine\ORM\Query Query
     */
    public function getArticleTopics($articleNr, $languageCode, $order = "asc")
    {
        $em = $this->getEntityManager();
        $articleTopicsIds = $em->getRepository('Newscoop\Entity\ArticleTopic')->getArticleTopicsIds($articleNr, true);
        $articleTopicsIds = $articleTopicsIds->getArrayResult();
        $topicsIds = array();
        foreach ($articleTopicsIds as $key => $value) {
            $topicsIds[$key] = $value[1];
        }

        $queryBuilder = $this->createQueryBuilder('t')
            ->where('t.id IN(:ids)')
            ->setParameters(array(
                'ids' => $topicsIds,
            ))
            ->orderBy('t.root, t.lft', $order);

        $countQueryBuilder = clone $queryBuilder;
        $countQueryBuilder->select('COUNT(t)');
        $count = $countQueryBuilder->getQuery()->getSingleScalarResult();

        $query = $queryBuilder->getQuery();
        $query->setHint('knp_paginator.count', $count);
        $query = $this->setTranslatableHint($query, $languageCode);

        return $query;
    }
}
