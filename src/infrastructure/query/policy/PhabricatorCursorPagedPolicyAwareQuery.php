<?php

/**
 * A query class which uses cursor-based paging. This paging is much more
 * performant than offset-based paging in the presence of policy filtering.
 *
 * @task clauses Building Query Clauses
 * @task appsearch Integration with ApplicationSearch
 * @task customfield Integration with CustomField
 * @task paging Paging
 * @task order Result Ordering
 * @task edgelogic Working with Edge Logic
 * @task spaces Working with Spaces
 */
abstract class PhabricatorCursorPagedPolicyAwareQuery
  extends PhabricatorPolicyAwareQuery {

  private $afterID;
  private $beforeID;
  private $applicationSearchConstraints = array();
  private $internalPaging;
  private $orderVector;
  private $groupVector;
  private $builtinOrder;
  private $edgeLogicConstraints = array();
  private $edgeLogicConstraintsAreValid = false;
  private $spacePHIDs;
  private $spaceIsArchived;
  private $ngrams = array();
  private $ferretEngine;
  private $ferretTokens = array();
  private $ferretTables = array();
  private $ferretQuery;
  private $ferretMetadata = array();

  protected function getPageCursors(array $page) {
    return array(
      $this->getResultCursor(head($page)),
      $this->getResultCursor(last($page)),
    );
  }

  protected function getResultCursor($object) {
    if (!is_object($object)) {
      throw new Exception(
        pht(
          'Expected object, got "%s".',
          gettype($object)));
    }

    return $object->getID();
  }

  protected function nextPage(array $page) {
    // See getPagingViewer() for a description of this flag.
    $this->internalPaging = true;

    if ($this->beforeID !== null) {
      $page = array_reverse($page, $preserve_keys = true);
      list($before, $after) = $this->getPageCursors($page);
      $this->beforeID = $before;
    } else {
      list($before, $after) = $this->getPageCursors($page);
      $this->afterID = $after;
    }
  }

  final public function setAfterID($object_id) {
    $this->afterID = $object_id;
    return $this;
  }

  final protected function getAfterID() {
    return $this->afterID;
  }

  final public function setBeforeID($object_id) {
    $this->beforeID = $object_id;
    return $this;
  }

  final protected function getBeforeID() {
    return $this->beforeID;
  }

  final public function getFerretMetadata() {
    if (!$this->supportsFerretEngine()) {
      throw new Exception(
        pht(
          'Unable to retrieve Ferret engine metadata, this class ("%s") does '.
          'not support the Ferret engine.',
          get_class($this)));
    }

    return $this->ferretMetadata;
  }

  protected function loadStandardPage(PhabricatorLiskDAO $table) {
    $rows = $this->loadStandardPageRows($table);
    return $table->loadAllFromArray($rows);
  }

  protected function loadStandardPageRows(PhabricatorLiskDAO $table) {
    $conn = $table->establishConnection('r');
    return $this->loadStandardPageRowsWithConnection(
      $conn,
      $table->getTableName());
  }

  protected function loadStandardPageRowsWithConnection(
    AphrontDatabaseConnection $conn,
    $table_name) {

    $query = $this->buildStandardPageQuery($conn, $table_name);

    $rows = queryfx_all($conn, '%Q', $query);
    $rows = $this->didLoadRawRows($rows);

    return $rows;
  }

  protected function buildStandardPageQuery(
    AphrontDatabaseConnection $conn,
    $table_name) {

    return qsprintf(
      $conn,
      '%Q FROM %T %Q %Q %Q %Q %Q %Q %Q',
      $this->buildSelectClause($conn),
      $table_name,
      (string)$this->getPrimaryTableAlias(),
      $this->buildJoinClause($conn),
      $this->buildWhereClause($conn),
      $this->buildGroupClause($conn),
      $this->buildHavingClause($conn),
      $this->buildOrderClause($conn),
      $this->buildLimitClause($conn));
  }

  protected function didLoadRawRows(array $rows) {
    if ($this->ferretEngine) {
      foreach ($rows as $row) {
        $phid = $row['phid'];

        $metadata = id(new PhabricatorFerretMetadata())
          ->setPHID($phid)
          ->setEngine($this->ferretEngine)
          ->setRelevance(idx($row, '_ft_rank'));

        $this->ferretMetadata[$phid] = $metadata;

        unset($row['_ft_rank']);
      }
    }

    return $rows;
  }

  /**
   * Get the viewer for making cursor paging queries.
   *
   * NOTE: You should ONLY use this viewer to load cursor objects while
   * building paging queries.
   *
   * Cursor paging can happen in two ways. First, the user can request a page
   * like `/stuff/?after=33`, which explicitly causes paging. Otherwise, we
   * can fall back to implicit paging if we filter some results out of a
   * result list because the user can't see them and need to go fetch some more
   * results to generate a large enough result list.
   *
   * In the first case, want to use the viewer's policies to load the object.
   * This prevents an attacker from figuring out information about an object
   * they can't see by executing queries like `/stuff/?after=33&order=name`,
   * which would otherwise give them a hint about the name of the object.
   * Generally, if a user can't see an object, they can't use it to page.
   *
   * In the second case, we need to load the object whether the user can see
   * it or not, because we need to examine new results. For example, if a user
   * loads `/stuff/` and we run a query for the first 100 items that they can
   * see, but the first 100 rows in the database aren't visible, we need to
   * be able to issue a query for the next 100 results. If we can't load the
   * cursor object, we'll fail or issue the same query over and over again.
   * So, generally, internal paging must bypass policy controls.
   *
   * This method returns the appropriate viewer, based on the context in which
   * the paging is occurring.
   *
   * @return PhabricatorUser Viewer for executing paging queries.
   */
  final protected function getPagingViewer() {
    if ($this->internalPaging) {
      return PhabricatorUser::getOmnipotentUser();
    } else {
      return $this->getViewer();
    }
  }

  final protected function buildLimitClause(AphrontDatabaseConnection $conn_r) {
    if ($this->shouldLimitResults()) {
      $limit = $this->getRawResultLimit();
      if ($limit) {
        return qsprintf($conn_r, 'LIMIT %d', $limit);
      }
    }

    return '';
  }

  protected function shouldLimitResults() {
    return true;
  }

  final protected function didLoadResults(array $results) {
    if ($this->beforeID) {
      $results = array_reverse($results, $preserve_keys = true);
    }

    return $results;
  }

  final public function executeWithCursorPager(AphrontCursorPagerView $pager) {
    $limit = $pager->getPageSize();

    $this->setLimit($limit + 1);

    if ($pager->getAfterID()) {
      $this->setAfterID($pager->getAfterID());
    } else if ($pager->getBeforeID()) {
      $this->setBeforeID($pager->getBeforeID());
    }

    $results = $this->execute();
    $count = count($results);

    $sliced_results = $pager->sliceResults($results);
    if ($sliced_results) {
      list($before, $after) = $this->getPageCursors($sliced_results);

      if ($pager->getBeforeID() || ($count > $limit)) {
        $pager->setNextPageID($after);
      }

      if ($pager->getAfterID() ||
         ($pager->getBeforeID() && ($count > $limit))) {
        $pager->setPrevPageID($before);
      }
    }

    return $sliced_results;
  }


  /**
   * Return the alias this query uses to identify the primary table.
   *
   * Some automatic query constructions may need to be qualified with a table
   * alias if the query performs joins which make column names ambiguous. If
   * this is the case, return the alias for the primary table the query
   * uses; generally the object table which has `id` and `phid` columns.
   *
   * @return string Alias for the primary table.
   */
  protected function getPrimaryTableAlias() {
    return null;
  }

  public function newResultObject() {
    return null;
  }


/* -(  Building Query Clauses  )--------------------------------------------- */


  /**
   * @task clauses
   */
  protected function buildSelectClause(AphrontDatabaseConnection $conn) {
    $parts = $this->buildSelectClauseParts($conn);
    return $this->formatSelectClause($parts);
  }


  /**
   * @task clauses
   */
  protected function buildSelectClauseParts(AphrontDatabaseConnection $conn) {
    $select = array();

    $alias = $this->getPrimaryTableAlias();
    if ($alias) {
      $select[] = qsprintf($conn, '%T.*', $alias);
    } else {
      $select[] = '*';
    }

    $select[] = $this->buildEdgeLogicSelectClause($conn);
    $select[] = $this->buildFerretSelectClause($conn);

    return $select;
  }


  /**
   * @task clauses
   */
  protected function buildJoinClause(AphrontDatabaseConnection $conn) {
    $joins = $this->buildJoinClauseParts($conn);
    return $this->formatJoinClause($joins);
  }


  /**
   * @task clauses
   */
  protected function buildJoinClauseParts(AphrontDatabaseConnection $conn) {
    $joins = array();
    $joins[] = $this->buildEdgeLogicJoinClause($conn);
    $joins[] = $this->buildApplicationSearchJoinClause($conn);
    $joins[] = $this->buildNgramsJoinClause($conn);
    $joins[] = $this->buildFerretJoinClause($conn);
    return $joins;
  }


  /**
   * @task clauses
   */
  protected function buildWhereClause(AphrontDatabaseConnection $conn) {
    $where = $this->buildWhereClauseParts($conn);
    return $this->formatWhereClause($where);
  }


  /**
   * @task clauses
   */
  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = array();
    $where[] = $this->buildPagingClause($conn);
    $where[] = $this->buildEdgeLogicWhereClause($conn);
    $where[] = $this->buildSpacesWhereClause($conn);
    $where[] = $this->buildNgramsWhereClause($conn);
    $where[] = $this->buildFerretWhereClause($conn);
    $where[] = $this->buildApplicationSearchWhereClause($conn);
    return $where;
  }


  /**
   * @task clauses
   */
  protected function buildHavingClause(AphrontDatabaseConnection $conn) {
    $having = $this->buildHavingClauseParts($conn);
    return $this->formatHavingClause($having);
  }


  /**
   * @task clauses
   */
  protected function buildHavingClauseParts(AphrontDatabaseConnection $conn) {
    $having = array();
    $having[] = $this->buildEdgeLogicHavingClause($conn);
    return $having;
  }


  /**
   * @task clauses
   */
  protected function buildGroupClause(AphrontDatabaseConnection $conn) {
    if (!$this->shouldGroupQueryResultRows()) {
      return '';
    }

    return qsprintf(
      $conn,
      'GROUP BY %Q',
      $this->getApplicationSearchObjectPHIDColumn());
  }


  /**
   * @task clauses
   */
  protected function shouldGroupQueryResultRows() {
    if ($this->shouldGroupEdgeLogicResultRows()) {
      return true;
    }

    if ($this->getApplicationSearchMayJoinMultipleRows()) {
      return true;
    }

    if ($this->shouldGroupNgramResultRows()) {
      return true;
    }

    if ($this->shouldGroupFerretResultRows()) {
      return true;
    }

    return false;
  }



/* -(  Paging  )------------------------------------------------------------- */


  /**
   * @task paging
   */
  protected function buildPagingClause(AphrontDatabaseConnection $conn) {
    $orderable = $this->getOrderableColumns();
    $vector = $this->getOrderVector();

    if ($this->beforeID !== null) {
      $cursor = $this->beforeID;
      $reversed = true;
    } else if ($this->afterID !== null) {
      $cursor = $this->afterID;
      $reversed = false;
    } else {
      // No paging is being applied to this query so we do not need to
      // construct a paging clause.
      return '';
    }

    $keys = array();
    foreach ($vector as $order) {
      $keys[] = $order->getOrderKey();
    }

    $value_map = $this->getPagingValueMap($cursor, $keys);

    $columns = array();
    foreach ($vector as $order) {
      $key = $order->getOrderKey();

      if (!array_key_exists($key, $value_map)) {
        throw new Exception(
          pht(
            'Query "%s" failed to return a value from getPagingValueMap() '.
            'for column "%s".',
            get_class($this),
            $key));
      }

      $column = $orderable[$key];
      $column['value'] = $value_map[$key];

      // If the vector component is reversed, we need to reverse whatever the
      // order of the column is.
      if ($order->getIsReversed()) {
        $column['reverse'] = !idx($column, 'reverse', false);
      }

      $columns[] = $column;
    }

    return $this->buildPagingClauseFromMultipleColumns(
      $conn,
      $columns,
      array(
        'reversed' => $reversed,
      ));
  }


  /**
   * @task paging
   */
  protected function getPagingValueMap($cursor, array $keys) {
    return array(
      'id' => $cursor,
    );
  }


  /**
   * @task paging
   */
  protected function loadCursorObject($cursor) {
    $query = newv(get_class($this), array())
      ->setViewer($this->getPagingViewer())
      ->withIDs(array((int)$cursor));

    $this->willExecuteCursorQuery($query);

    $object = $query->executeOne();
    if (!$object) {
      throw new Exception(
        pht(
          'Cursor "%s" does not identify a valid object in query "%s".',
          $cursor,
          get_class($this)));
    }

    return $object;
  }


  /**
   * @task paging
   */
  protected function willExecuteCursorQuery(
    PhabricatorCursorPagedPolicyAwareQuery $query) {
    return;
  }


  /**
   * Simplifies the task of constructing a paging clause across multiple
   * columns. In the general case, this looks like:
   *
   *   A > a OR (A = a AND B > b) OR (A = a AND B = b AND C > c)
   *
   * To build a clause, specify the name, type, and value of each column
   * to include:
   *
   *   $this->buildPagingClauseFromMultipleColumns(
   *     $conn_r,
   *     array(
   *       array(
   *         'table' => 't',
   *         'column' => 'title',
   *         'type' => 'string',
   *         'value' => $cursor->getTitle(),
   *         'reverse' => true,
   *       ),
   *       array(
   *         'table' => 't',
   *         'column' => 'id',
   *         'type' => 'int',
   *         'value' => $cursor->getID(),
   *       ),
   *     ),
   *     array(
   *       'reversed' => $is_reversed,
   *     ));
   *
   * This method will then return a composable clause for inclusion in WHERE.
   *
   * @param AphrontDatabaseConnection Connection query will execute on.
   * @param list<map> Column description dictionaries.
   * @param map Additional construction options.
   * @return string Query clause.
   * @task paging
   */
  final protected function buildPagingClauseFromMultipleColumns(
    AphrontDatabaseConnection $conn,
    array $columns,
    array $options) {

    foreach ($columns as $column) {
      PhutilTypeSpec::checkMap(
        $column,
        array(
          'table' => 'optional string|null',
          'column' => 'string',
          'value' => 'wild',
          'type' => 'string',
          'reverse' => 'optional bool',
          'unique' => 'optional bool',
          'null' => 'optional string|null',
        ));
    }

    PhutilTypeSpec::checkMap(
      $options,
      array(
        'reversed' => 'optional bool',
      ));

    $is_query_reversed = idx($options, 'reversed', false);

    $clauses = array();
    $accumulated = array();
    $last_key = last_key($columns);
    foreach ($columns as $key => $column) {
      $type = $column['type'];

      $null = idx($column, 'null');
      if ($column['value'] === null) {
        if ($null) {
          $value = null;
        } else {
          throw new Exception(
            pht(
              'Column "%s" has null value, but does not specify a null '.
              'behavior.',
              $key));
        }
      } else {
        switch ($type) {
          case 'int':
            $value = qsprintf($conn, '%d', $column['value']);
            break;
          case 'float':
            $value = qsprintf($conn, '%f', $column['value']);
            break;
          case 'string':
            $value = qsprintf($conn, '%s', $column['value']);
            break;
          default:
            throw new Exception(
              pht(
                'Column "%s" has unknown column type "%s".',
                $column['column'],
                $type));
        }
      }

      $is_column_reversed = idx($column, 'reverse', false);
      $reverse = ($is_query_reversed xor $is_column_reversed);

      $clause = $accumulated;

      $table_name = idx($column, 'table');
      $column_name = $column['column'];
      if ($table_name !== null) {
        $field = qsprintf($conn, '%T.%T', $table_name, $column_name);
      } else {
        $field = qsprintf($conn, '%T', $column_name);
      }

      $parts = array();
      if ($null) {
        $can_page_if_null = ($null === 'head');
        $can_page_if_nonnull = ($null === 'tail');

        if ($reverse) {
          $can_page_if_null = !$can_page_if_null;
          $can_page_if_nonnull = !$can_page_if_nonnull;
        }

        $subclause = null;
        if ($can_page_if_null && $value === null) {
          $parts[] = qsprintf(
            $conn,
            '(%Q IS NOT NULL)',
            $field);
        } else if ($can_page_if_nonnull && $value !== null) {
          $parts[] = qsprintf(
            $conn,
            '(%Q IS NULL)',
            $field);
        }
      }

      if ($value !== null) {
        $parts[] = qsprintf(
          $conn,
          '%Q %Q %Q',
          $field,
          $reverse ? '>' : '<',
          $value);
      }

      if ($parts) {
        if (count($parts) > 1) {
          $clause[] = '('.implode(') OR (', $parts).')';
        } else {
          $clause[] = head($parts);
        }
      }

      if ($clause) {
        if (count($clause) > 1) {
          $clauses[] = '('.implode(') AND (', $clause).')';
        } else {
          $clauses[] = head($clause);
        }
      }

      if ($value === null) {
        $accumulated[] = qsprintf(
          $conn,
          '%Q IS NULL',
          $field);
      } else {
        $accumulated[] = qsprintf(
          $conn,
          '%Q = %Q',
          $field,
          $value);
      }
    }

    return '('.implode(') OR (', $clauses).')';
  }


/* -(  Result Ordering  )---------------------------------------------------- */


  /**
   * Select a result ordering.
   *
   * This is a high-level method which selects an ordering from a predefined
   * list of builtin orders, as provided by @{method:getBuiltinOrders}. These
   * options are user-facing and not exhaustive, but are generally convenient
   * and meaningful.
   *
   * You can also use @{method:setOrderVector} to specify a low-level ordering
   * across individual orderable columns. This offers greater control but is
   * also more involved.
   *
   * @param string Key of a builtin order supported by this query.
   * @return this
   * @task order
   */
  public function setOrder($order) {
    $aliases = $this->getBuiltinOrderAliasMap();

    if (empty($aliases[$order])) {
      throw new Exception(
        pht(
          'Query "%s" does not support a builtin order "%s". Supported orders '.
          'are: %s.',
          get_class($this),
          $order,
          implode(', ', array_keys($aliases))));
    }

    $this->builtinOrder = $aliases[$order];
    $this->orderVector = null;

    return $this;
  }


  /**
   * Set a grouping order to apply before primary result ordering.
   *
   * This allows you to preface the query order vector with additional orders,
   * so you can effect "group by" queries while still respecting "order by".
   *
   * This is a high-level method which works alongside @{method:setOrder}. For
   * lower-level control over order vectors, use @{method:setOrderVector}.
   *
   * @param PhabricatorQueryOrderVector|list<string> List of order keys.
   * @return this
   * @task order
   */
  public function setGroupVector($vector) {
    $this->groupVector = $vector;
    $this->orderVector = null;

    return $this;
  }


  /**
   * Get builtin orders for this class.
   *
   * In application UIs, we want to be able to present users with a small
   * selection of meaningful order options (like "Order by Title") rather than
   * an exhaustive set of column ordering options.
   *
   * Meaningful user-facing orders are often really orders across multiple
   * columns: for example, a "title" ordering is usually implemented as a
   * "title, id" ordering under the hood.
   *
   * Builtin orders provide a mapping from convenient, understandable
   * user-facing orders to implementations.
   *
   * A builtin order should provide these keys:
   *
   *   - `vector` (`list<string>`): The actual order vector to use.
   *   - `name` (`string`): Human-readable order name.
   *
   * @return map<string, wild> Map from builtin order keys to specification.
   * @task order
   */
  public function getBuiltinOrders() {
    $orders = array(
      'newest' => array(
        'vector' => array('id'),
        'name' => pht('Creation (Newest First)'),
        'aliases' => array('created'),
      ),
      'oldest' => array(
        'vector' => array('-id'),
        'name' => pht('Creation (Oldest First)'),
      ),
    );

    $object = $this->newResultObject();
    if ($object instanceof PhabricatorCustomFieldInterface) {
      $list = PhabricatorCustomField::getObjectFields(
        $object,
        PhabricatorCustomField::ROLE_APPLICATIONSEARCH);
      foreach ($list->getFields() as $field) {
        $index = $field->buildOrderIndex();
        if (!$index) {
          continue;
        }

        $legacy_key = 'custom:'.$field->getFieldKey();
        $modern_key = $field->getModernFieldKey();

        $orders[$modern_key] = array(
          'vector' => array($modern_key, 'id'),
          'name' => $field->getFieldName(),
          'aliases' => array($legacy_key),
        );

        $orders['-'.$modern_key] = array(
          'vector' => array('-'.$modern_key, '-id'),
          'name' => pht('%s (Reversed)', $field->getFieldName()),
        );
      }
    }

    if ($this->supportsFerretEngine()) {
      $orders['relevance'] = array(
        'vector' => array('rank', 'fulltext-modified', 'id'),
        'name' => pht('Relevance'),
      );
    }

    return $orders;
  }

  public function getBuiltinOrderAliasMap() {
    $orders = $this->getBuiltinOrders();

    $map = array();
    foreach ($orders as $key => $order) {
      $keys = array();
      $keys[] = $key;
      foreach (idx($order, 'aliases', array()) as $alias) {
        $keys[] = $alias;
      }

      foreach ($keys as $alias) {
        if (isset($map[$alias])) {
          throw new Exception(
            pht(
              'Two builtin orders ("%s" and "%s") define the same key or '.
              'alias ("%s"). Each order alias and key must be unique and '.
              'identify a single order.',
              $key,
              $map[$alias],
              $alias));
        }
        $map[$alias] = $key;
      }
    }

    return $map;
  }


  /**
   * Set a low-level column ordering.
   *
   * This is a low-level method which offers granular control over column
   * ordering. In most cases, applications can more easily use
   * @{method:setOrder} to choose a high-level builtin order.
   *
   * To set an order vector, specify a list of order keys as provided by
   * @{method:getOrderableColumns}.
   *
   * @param PhabricatorQueryOrderVector|list<string> List of order keys.
   * @return this
   * @task order
   */
  public function setOrderVector($vector) {
    $vector = PhabricatorQueryOrderVector::newFromVector($vector);

    $orderable = $this->getOrderableColumns();

    // Make sure that all the components identify valid columns.
    $unique = array();
    foreach ($vector as $order) {
      $key = $order->getOrderKey();
      if (empty($orderable[$key])) {
        $valid = implode(', ', array_keys($orderable));
        throw new Exception(
          pht(
            'This query ("%s") does not support sorting by order key "%s". '.
            'Supported orders are: %s.',
            get_class($this),
            $key,
            $valid));
      }

      $unique[$key] = idx($orderable[$key], 'unique', false);
    }

    // Make sure that the last column is unique so that this is a strong
    // ordering which can be used for paging.
    $last = last($unique);
    if ($last !== true) {
      throw new Exception(
        pht(
          'Order vector "%s" is invalid: the last column in an order must '.
          'be a column with unique values, but "%s" is not unique.',
          $vector->getAsString(),
          last_key($unique)));
    }

    // Make sure that other columns are not unique; an ordering like "id, name"
    // does not make sense because only "id" can ever have an effect.
    array_pop($unique);
    foreach ($unique as $key => $is_unique) {
      if ($is_unique) {
        throw new Exception(
          pht(
            'Order vector "%s" is invalid: only the last column in an order '.
            'may be unique, but "%s" is a unique column and not the last '.
            'column in the order.',
            $vector->getAsString(),
            $key));
      }
    }

    $this->orderVector = $vector;
    return $this;
  }


  /**
   * Get the effective order vector.
   *
   * @return PhabricatorQueryOrderVector Effective vector.
   * @task order
   */
  protected function getOrderVector() {
    if (!$this->orderVector) {
      if ($this->builtinOrder !== null) {
        $builtin_order = idx($this->getBuiltinOrders(), $this->builtinOrder);
        $vector = $builtin_order['vector'];
      } else {
        $vector = $this->getDefaultOrderVector();
      }

      if ($this->groupVector) {
        $group = PhabricatorQueryOrderVector::newFromVector($this->groupVector);
        $group->appendVector($vector);
        $vector = $group;
      }

      $vector = PhabricatorQueryOrderVector::newFromVector($vector);

      // We call setOrderVector() here to apply checks to the default vector.
      // This catches any errors in the implementation.
      $this->setOrderVector($vector);
    }

    return $this->orderVector;
  }


  /**
   * @task order
   */
  protected function getDefaultOrderVector() {
    return array('id');
  }


  /**
   * @task order
   */
  public function getOrderableColumns() {
    $cache = PhabricatorCaches::getRequestCache();
    $class = get_class($this);
    $cache_key = 'query.orderablecolumns.'.$class;

    $columns = $cache->getKey($cache_key);
    if ($columns !== null) {
      return $columns;
    }

    $columns = array(
      'id' => array(
        'table' => $this->getPrimaryTableAlias(),
        'column' => 'id',
        'reverse' => false,
        'type' => 'int',
        'unique' => true,
      ),
    );

    $object = $this->newResultObject();
    if ($object instanceof PhabricatorCustomFieldInterface) {
      $list = PhabricatorCustomField::getObjectFields(
        $object,
        PhabricatorCustomField::ROLE_APPLICATIONSEARCH);
      foreach ($list->getFields() as $field) {
        $index = $field->buildOrderIndex();
        if (!$index) {
          continue;
        }

        $digest = $field->getFieldIndex();

        $key = $field->getModernFieldKey();

        $columns[$key] = array(
          'table' => 'appsearch_order_'.$digest,
          'column' => 'indexValue',
          'type' => $index->getIndexValueType(),
          'null' => 'tail',
          'customfield' => true,
          'customfield.index.table' => $index->getTableName(),
          'customfield.index.key' => $digest,
        );
      }
    }

    if ($this->supportsFerretEngine()) {
      $columns['rank'] = array(
        'table' => null,
        'column' => '_ft_rank',
        'type' => 'int',
      );
      $columns['fulltext-created'] = array(
        'table' => 'ft_doc',
        'column' => 'epochCreated',
        'type' => 'int',
      );
      $columns['fulltext-modified'] = array(
        'table' => 'ft_doc',
        'column' => 'epochModified',
        'type' => 'int',
      );
    }

    $cache->setKey($cache_key, $columns);

    return $columns;
  }


  /**
   * @task order
   */
  final protected function buildOrderClause(
    AphrontDatabaseConnection $conn,
    $for_union = false) {

    $orderable = $this->getOrderableColumns();
    $vector = $this->getOrderVector();

    $parts = array();
    foreach ($vector as $order) {
      $part = $orderable[$order->getOrderKey()];
      if ($order->getIsReversed()) {
        $part['reverse'] = !idx($part, 'reverse', false);
      }
      $parts[] = $part;
    }

    return $this->formatOrderClause($conn, $parts, $for_union);
  }


  /**
   * @task order
   */
  protected function formatOrderClause(
    AphrontDatabaseConnection $conn,
    array $parts,
    $for_union = false) {

    $is_query_reversed = false;
    if ($this->getBeforeID()) {
      $is_query_reversed = !$is_query_reversed;
    }

    $sql = array();
    foreach ($parts as $key => $part) {
      $is_column_reversed = !empty($part['reverse']);

      $descending = true;
      if ($is_query_reversed) {
        $descending = !$descending;
      }

      if ($is_column_reversed) {
        $descending = !$descending;
      }

      $table = idx($part, 'table');

      // When we're building an ORDER BY clause for a sequence of UNION
      // statements, we can't refer to tables from the subqueries.
      if ($for_union) {
        $table = null;
      }

      $column = $part['column'];

      if ($table !== null) {
        $field = qsprintf($conn, '%T.%T', $table, $column);
      } else {
        $field = qsprintf($conn, '%T', $column);
      }

      $null = idx($part, 'null');
      if ($null) {
        switch ($null) {
          case 'head':
            $null_field = qsprintf($conn, '(%Q IS NULL)', $field);
            break;
          case 'tail':
            $null_field = qsprintf($conn, '(%Q IS NOT NULL)', $field);
            break;
          default:
            throw new Exception(
              pht(
                'NULL value "%s" is invalid. Valid values are "head" and '.
                '"tail".',
                $null));
        }

        if ($descending) {
          $sql[] = qsprintf($conn, '%Q DESC', $null_field);
        } else {
          $sql[] = qsprintf($conn, '%Q ASC', $null_field);
        }
      }

      if ($descending) {
        $sql[] = qsprintf($conn, '%Q DESC', $field);
      } else {
        $sql[] = qsprintf($conn, '%Q ASC', $field);
      }
    }

    return qsprintf($conn, 'ORDER BY %Q', implode(', ', $sql));
  }


/* -(  Application Search  )------------------------------------------------- */


  /**
   * Constrain the query with an ApplicationSearch index, requiring field values
   * contain at least one of the values in a set.
   *
   * This constraint can build the most common types of queries, like:
   *
   *   - Find users with shirt sizes "X" or "XL".
   *   - Find shoes with size "13".
   *
   * @param PhabricatorCustomFieldIndexStorage Table where the index is stored.
   * @param string|list<string> One or more values to filter by.
   * @return this
   * @task appsearch
   */
  public function withApplicationSearchContainsConstraint(
    PhabricatorCustomFieldIndexStorage $index,
    $value) {

    $values = (array)$value;

    $data_values = array();
    $constraint_values = array();
    foreach ($values as $value) {
      if ($value instanceof PhabricatorQueryConstraint) {
        $constraint_values[] = $value;
      } else {
        $data_values[] = $value;
      }
    }

    $alias = 'appsearch_'.count($this->applicationSearchConstraints);

    $this->applicationSearchConstraints[] = array(
      'type'  => $index->getIndexValueType(),
      'cond'  => '=',
      'table' => $index->getTableName(),
      'index' => $index->getIndexKey(),
      'alias' => $alias,
      'value' => $values,
      'data' => $data_values,
      'constraints' => $constraint_values,
    );

    return $this;
  }


  /**
   * Constrain the query with an ApplicationSearch index, requiring values
   * exist in a given range.
   *
   * This constraint is useful for expressing date ranges:
   *
   *   - Find events between July 1st and July 7th.
   *
   * The ends of the range are inclusive, so a `$min` of `3` and a `$max` of
   * `5` will match fields with values `3`, `4`, or `5`. Providing `null` for
   * either end of the range will leave that end of the constraint open.
   *
   * @param PhabricatorCustomFieldIndexStorage Table where the index is stored.
   * @param int|null Minimum permissible value, inclusive.
   * @param int|null Maximum permissible value, inclusive.
   * @return this
   * @task appsearch
   */
  public function withApplicationSearchRangeConstraint(
    PhabricatorCustomFieldIndexStorage $index,
    $min,
    $max) {

    $index_type = $index->getIndexValueType();
    if ($index_type != 'int') {
      throw new Exception(
        pht(
          'Attempting to apply a range constraint to a field with index type '.
          '"%s", expected type "%s".',
          $index_type,
          'int'));
    }

    $alias = 'appsearch_'.count($this->applicationSearchConstraints);

    $this->applicationSearchConstraints[] = array(
      'type' => $index->getIndexValueType(),
      'cond' => 'range',
      'table' => $index->getTableName(),
      'index' => $index->getIndexKey(),
      'alias' => $alias,
      'value' => array($min, $max),
    );

    return $this;
  }


  /**
   * Get the name of the query's primary object PHID column, for constructing
   * JOIN clauses. Normally (and by default) this is just `"phid"`, but it may
   * be something more exotic.
   *
   * See @{method:getPrimaryTableAlias} if the column needs to be qualified with
   * a table alias.
   *
   * @return string Column name.
   * @task appsearch
   */
  protected function getApplicationSearchObjectPHIDColumn() {
    if ($this->getPrimaryTableAlias()) {
      $prefix = $this->getPrimaryTableAlias().'.';
    } else {
      $prefix = '';
    }

    return $prefix.'phid';
  }


  /**
   * Determine if the JOINs built by ApplicationSearch might cause each primary
   * object to return multiple result rows. Generally, this means the query
   * needs an extra GROUP BY clause.
   *
   * @return bool True if the query may return multiple rows for each object.
   * @task appsearch
   */
  protected function getApplicationSearchMayJoinMultipleRows() {
    foreach ($this->applicationSearchConstraints as $constraint) {
      $type = $constraint['type'];
      $value = $constraint['value'];
      $cond = $constraint['cond'];

      switch ($cond) {
        case '=':
          switch ($type) {
            case 'string':
            case 'int':
              if (count($value) > 1) {
                return true;
              }
              break;
            default:
              throw new Exception(pht('Unknown index type "%s"!', $type));
          }
          break;
        case 'range':
          // NOTE: It's possible to write a custom field where multiple rows
          // match a range constraint, but we don't currently ship any in the
          // upstream and I can't immediately come up with cases where this
          // would make sense.
          break;
        default:
          throw new Exception(pht('Unknown constraint condition "%s"!', $cond));
      }
    }

    return false;
  }


  /**
   * Construct a GROUP BY clause appropriate for ApplicationSearch constraints.
   *
   * @param AphrontDatabaseConnection Connection executing the query.
   * @return string Group clause.
   * @task appsearch
   */
  protected function buildApplicationSearchGroupClause(
    AphrontDatabaseConnection $conn_r) {

    if ($this->getApplicationSearchMayJoinMultipleRows()) {
      return qsprintf(
        $conn_r,
        'GROUP BY %Q',
        $this->getApplicationSearchObjectPHIDColumn());
    } else {
      return '';
    }
  }


  /**
   * Construct a JOIN clause appropriate for applying ApplicationSearch
   * constraints.
   *
   * @param AphrontDatabaseConnection Connection executing the query.
   * @return string Join clause.
   * @task appsearch
   */
  protected function buildApplicationSearchJoinClause(
    AphrontDatabaseConnection $conn) {

    $joins = array();
    foreach ($this->applicationSearchConstraints as $key => $constraint) {
      $table = $constraint['table'];
      $alias = $constraint['alias'];
      $index = $constraint['index'];
      $cond = $constraint['cond'];
      $phid_column = $this->getApplicationSearchObjectPHIDColumn();
      switch ($cond) {
        case '=':
          // Figure out whether we need to do a LEFT JOIN or not. We need to
          // LEFT JOIN if we're going to select "IS NULL" rows.
          $join_type = 'JOIN';
          foreach ($constraint['constraints'] as $query_constraint) {
            $op = $query_constraint->getOperator();
            if ($op === PhabricatorQueryConstraint::OPERATOR_NULL) {
              $join_type = 'LEFT JOIN';
              break;
            }
          }

          $joins[] = qsprintf(
            $conn,
            '%Q %T %T ON %T.objectPHID = %Q
              AND %T.indexKey = %s',
            $join_type,
            $table,
            $alias,
            $alias,
            $phid_column,
            $alias,
            $index);
          break;
        case 'range':
          list($min, $max) = $constraint['value'];
          if (($min === null) && ($max === null)) {
            // If there's no actual range constraint, just move on.
            break;
          }

          if ($min === null) {
            $constraint_clause = qsprintf(
              $conn,
              '%T.indexValue <= %d',
              $alias,
              $max);
          } else if ($max === null) {
            $constraint_clause = qsprintf(
              $conn,
              '%T.indexValue >= %d',
              $alias,
              $min);
          } else {
            $constraint_clause = qsprintf(
              $conn,
              '%T.indexValue BETWEEN %d AND %d',
              $alias,
              $min,
              $max);
          }

          $joins[] = qsprintf(
            $conn,
            'JOIN %T %T ON %T.objectPHID = %Q
              AND %T.indexKey = %s
              AND (%Q)',
            $table,
            $alias,
            $alias,
            $phid_column,
            $alias,
            $index,
            $constraint_clause);
          break;
        default:
          throw new Exception(pht('Unknown constraint condition "%s"!', $cond));
      }
    }

    $phid_column = $this->getApplicationSearchObjectPHIDColumn();
    $orderable = $this->getOrderableColumns();

    $vector = $this->getOrderVector();
    foreach ($vector as $order) {
      $spec = $orderable[$order->getOrderKey()];
      if (empty($spec['customfield'])) {
        continue;
      }

      $table = $spec['customfield.index.table'];
      $alias = $spec['table'];
      $key = $spec['customfield.index.key'];

      $joins[] = qsprintf(
        $conn,
        'LEFT JOIN %T %T ON %T.objectPHID = %Q
          AND %T.indexKey = %s',
        $table,
        $alias,
        $alias,
        $phid_column,
        $alias,
        $key);
    }

    return implode(' ', $joins);
  }

  /**
   * Construct a WHERE clause appropriate for applying ApplicationSearch
   * constraints.
   *
   * @param AphrontDatabaseConnection Connection executing the query.
   * @return list<string> Where clause parts.
   * @task appsearch
   */
  protected function buildApplicationSearchWhereClause(
    AphrontDatabaseConnection $conn) {

    $where = array();

    foreach ($this->applicationSearchConstraints as $key => $constraint) {
      $alias = $constraint['alias'];
      $cond = $constraint['cond'];
      $type = $constraint['type'];

      $data_values = $constraint['data'];
      $constraint_values = $constraint['constraints'];

      $constraint_parts = array();
      switch ($cond) {
        case '=':
          if ($data_values) {
            switch ($type) {
              case 'string':
                $constraint_parts[] = qsprintf(
                  $conn,
                  '%T.indexValue IN (%Ls)',
                  $alias,
                  $data_values);
                break;
              case 'int':
                $constraint_parts[] = qsprintf(
                  $conn,
                  '%T.indexValue IN (%Ld)',
                  $alias,
                  $data_values);
                break;
              default:
                throw new Exception(pht('Unknown index type "%s"!', $type));
            }
          }

          if ($constraint_values) {
            foreach ($constraint_values as $value) {
              $op = $value->getOperator();
              switch ($op) {
                case PhabricatorQueryConstraint::OPERATOR_NULL:
                  $constraint_parts[] = qsprintf(
                    $conn,
                    '%T.indexValue IS NULL',
                    $alias);
                  break;
                case PhabricatorQueryConstraint::OPERATOR_ANY:
                  $constraint_parts[] = qsprintf(
                    $conn,
                    '%T.indexValue IS NOT NULL',
                    $alias);
                  break;
                default:
                  throw new Exception(
                    pht(
                      'No support for applying operator "%s" against '.
                      'index of type "%s".',
                      $op,
                      $type));
              }
            }
          }

          if ($constraint_parts) {
            $where[] = '('.implode(') OR (', $constraint_parts).')';
          }
          break;
      }
    }

    return $where;
  }


/* -(  Integration with CustomField  )--------------------------------------- */


  /**
   * @task customfield
   */
  protected function getPagingValueMapForCustomFields(
    PhabricatorCustomFieldInterface $object) {

    // We have to get the current field values on the cursor object.
    $fields = PhabricatorCustomField::getObjectFields(
      $object,
      PhabricatorCustomField::ROLE_APPLICATIONSEARCH);
    $fields->setViewer($this->getViewer());
    $fields->readFieldsFromStorage($object);

    $map = array();
    foreach ($fields->getFields() as $field) {
      $map['custom:'.$field->getFieldKey()] = $field->getValueForStorage();
    }

    return $map;
  }


  /**
   * @task customfield
   */
  protected function isCustomFieldOrderKey($key) {
    $prefix = 'custom:';
    return !strncmp($key, $prefix, strlen($prefix));
  }


/* -(  Ferret  )------------------------------------------------------------- */


  public function supportsFerretEngine() {
    $object = $this->newResultObject();
    return ($object instanceof PhabricatorFerretInterface);
  }

  public function withFerretQuery(
    PhabricatorFerretEngine $engine,
    PhabricatorSavedQuery $query) {

    if (!$this->supportsFerretEngine()) {
      throw new Exception(
        pht(
          'Query ("%s") does not support the Ferret fulltext engine.',
          get_class($this)));
    }

    $this->ferretEngine = $engine;
    $this->ferretQuery = $query;

    return $this;
  }

  public function getFerretTokens() {
    if (!$this->supportsFerretEngine()) {
      throw new Exception(
        pht(
          'Query ("%s") does not support the Ferret fulltext engine.',
          get_class($this)));
    }

    return $this->ferretTokens;
  }

  public function withFerretConstraint(
    PhabricatorFerretEngine $engine,
    array $fulltext_tokens) {

    if (!$this->supportsFerretEngine()) {
      throw new Exception(
        pht(
          'Query ("%s") does not support the Ferret fulltext engine.',
          get_class($this)));
    }

    if ($this->ferretEngine) {
      throw new Exception(
        pht(
          'Query may not have multiple fulltext constraints.'));
    }

    if (!$fulltext_tokens) {
      return $this;
    }

    $this->ferretEngine = $engine;
    $this->ferretTokens = $fulltext_tokens;

    $current_function = $engine->getDefaultFunctionKey();
    $table_map = array();
    $idx = 1;
    foreach ($this->ferretTokens as $fulltext_token) {
      $raw_token = $fulltext_token->getToken();
      $function = $raw_token->getFunction();

      if ($function === null) {
        $function = $current_function;
      }

      $raw_field = $engine->getFieldForFunction($function);

      if (!isset($table_map[$function])) {
        $alias = 'ftfield_'.$idx++;
        $table_map[$function] = array(
          'alias' => $alias,
          'key' => $raw_field,
        );
      }

      $current_function = $function;
    }

    // Join the title field separately so we can rank results.
    $table_map['rank'] = array(
      'alias' => 'ft_rank',
      'key' => PhabricatorSearchDocumentFieldType::FIELD_TITLE,
    );

    $this->ferretTables = $table_map;

    return $this;
  }

  protected function buildFerretSelectClause(AphrontDatabaseConnection $conn) {
    $select = array();

    if (!$this->supportsFerretEngine()) {
      return $select;
    }

    $vector = $this->getOrderVector();
    if (!$vector->containsKey('rank')) {
      // We only need to SELECT the virtual "_ft_rank" column if we're
      // actually sorting the results by rank.
      return $select;
    }

    if (!$this->ferretEngine) {
      $select[] = '0 _ft_rank';
      return $select;
    }

    $engine = $this->ferretEngine;
    $stemmer = $engine->newStemmer();

    $op_sub = PhutilSearchQueryCompiler::OPERATOR_SUBSTRING;
    $op_not = PhutilSearchQueryCompiler::OPERATOR_NOT;
    $table_alias = 'ft_rank';

    $parts = array();
    foreach ($this->ferretTokens as $fulltext_token) {
      $raw_token = $fulltext_token->getToken();
      $value = $raw_token->getValue();

      if ($raw_token->getOperator() == $op_not) {
        // Ignore "not" terms when ranking, since they aren't useful.
        continue;
      }

      if ($raw_token->getOperator() == $op_sub) {
        $is_substring = true;
      } else {
        $is_substring = false;
      }

      if ($is_substring) {
        $parts[] = qsprintf(
          $conn,
          'IF(%T.rawCorpus LIKE %~, 2, 0)',
          $table_alias,
          $value);
        continue;
      }

      if ($raw_token->isQuoted()) {
        $is_quoted = true;
        $is_stemmed = false;
      } else {
        $is_quoted = false;
        $is_stemmed = true;
      }

      $term_constraints = array();

      $term_value = $engine->newTermsCorpus($value);

      $parts[] = qsprintf(
        $conn,
        'IF(%T.termCorpus LIKE %~, 2, 0)',
        $table_alias,
        $term_value);

      if ($is_stemmed) {
        $stem_value = $stemmer->stemToken($value);
        $stem_value = $engine->newTermsCorpus($stem_value);

        $parts[] = qsprintf(
          $conn,
          'IF(%T.normalCorpus LIKE %~, 1, 0)',
          $table_alias,
          $stem_value);
      }
    }

    $parts[] = '0';

    $select[] = qsprintf(
      $conn,
      '%Q _ft_rank',
      implode(' + ', $parts));

    return $select;
  }

  protected function buildFerretJoinClause(AphrontDatabaseConnection $conn) {
    if (!$this->ferretEngine) {
      return array();
    }

    $op_sub = PhutilSearchQueryCompiler::OPERATOR_SUBSTRING;
    $op_not = PhutilSearchQueryCompiler::OPERATOR_NOT;

    $engine = $this->ferretEngine;
    $stemmer = $engine->newStemmer();

    $ngram_table = $engine->getNgramsTableName();

    $flat = array();
    foreach ($this->ferretTokens as $fulltext_token) {
      $raw_token = $fulltext_token->getToken();

      // If this is a negated term like "-pomegranate", don't join the ngram
      // table since we aren't looking for documents with this term. (We could
      // LEFT JOIN the table and require a NULL row, but this is probably more
      // trouble than it's worth.)
      if ($raw_token->getOperator() == $op_not) {
        continue;
      }

      $value = $raw_token->getValue();

      $length = count(phutil_utf8v($value));

      if ($raw_token->getOperator() == $op_sub) {
        $is_substring = true;
      } else {
        $is_substring = false;
      }

      // If the user specified a substring query for a substring which is
      // shorter than the ngram length, we can't use the ngram index, so
      // don't do a join. We'll fall back to just doing LIKE on the full
      // corpus.
      if ($is_substring) {
        if ($length < 3) {
          continue;
        }
      }

      if ($raw_token->isQuoted()) {
        $is_stemmed = false;
      } else {
        $is_stemmed = true;
      }

      if ($is_substring) {
        $ngrams = $engine->getSubstringNgramsFromString($value);
      } else {
        $terms_value = $engine->newTermsCorpus($value);
        $ngrams = $engine->getTermNgramsFromString($terms_value);

        // If this is a stemmed term, only look for ngrams present in both the
        // unstemmed and stemmed variations.
        if ($is_stemmed) {
          // Trim the boundary space characters so the stemmer recognizes this
          // is (or, at least, may be) a normal word and activates.
          $terms_value = trim($terms_value, ' ');
          $stem_value = $stemmer->stemToken($terms_value);
          $stem_ngrams = $engine->getTermNgramsFromString($stem_value);
          $ngrams = array_intersect($ngrams, $stem_ngrams);
        }
      }

      foreach ($ngrams as $ngram) {
        $flat[] = array(
          'table' => $ngram_table,
          'ngram' => $ngram,
        );
      }
    }

    // Remove common ngrams, like "the", which occur too frequently in
    // documents to be useful in constraining the query. The best ngrams
    // are obscure sequences which occur in very few documents.

    if ($flat) {
      $common_ngrams = queryfx_all(
        $conn,
        'SELECT ngram FROM %T WHERE ngram IN (%Ls)',
        $engine->getCommonNgramsTableName(),
        ipull($flat, 'ngram'));
      $common_ngrams = ipull($common_ngrams, 'ngram', 'ngram');

      foreach ($flat as $key => $spec) {
        $ngram = $spec['ngram'];
        if (isset($common_ngrams[$ngram])) {
          unset($flat[$key]);
          continue;
        }

        // NOTE: MySQL discards trailing whitespace in CHAR(X) columns.
        $trim_ngram = rtrim($ngram, ' ');
        if (isset($common_ngrams[$trim_ngram])) {
          unset($flat[$key]);
          continue;
        }
      }
    }

    // MySQL only allows us to join a maximum of 61 tables per query. Each
    // ngram is going to cost us a join toward that limit, so if the user
    // specified a very long query string, just pick 16 of the ngrams
    // at random.
    if (count($flat) > 16) {
      shuffle($flat);
      $flat = array_slice($flat, 0, 16);
    }

    $alias = $this->getPrimaryTableAlias();
    if ($alias) {
      $phid_column = qsprintf($conn, '%T.%T', $alias, 'phid');
    } else {
      $phid_column = qsprintf($conn, '%T', 'phid');
    }

    $document_table = $engine->getDocumentTableName();
    $field_table = $engine->getFieldTableName();

    $joins = array();
    $joins[] = qsprintf(
      $conn,
      'JOIN %T ft_doc ON ft_doc.objectPHID = %Q',
      $document_table,
      $phid_column);

    $idx = 1;
    foreach ($flat as $spec) {
      $table = $spec['table'];
      $ngram = $spec['ngram'];

      $alias = 'ftngram_'.$idx++;

      $joins[] = qsprintf(
        $conn,
        'JOIN %T %T ON %T.documentID = ft_doc.id AND %T.ngram = %s',
        $table,
        $alias,
        $alias,
        $alias,
        $ngram);
    }

    foreach ($this->ferretTables as $table) {
      $alias = $table['alias'];

      $joins[] = qsprintf(
        $conn,
        'JOIN %T %T ON ft_doc.id = %T.documentID
          AND %T.fieldKey = %s',
        $field_table,
        $alias,
        $alias,
        $alias,
        $table['key']);
    }

    return $joins;
  }

  protected function buildFerretWhereClause(AphrontDatabaseConnection $conn) {
    if (!$this->ferretEngine) {
      return array();
    }

    $engine = $this->ferretEngine;
    $stemmer = $engine->newStemmer();
    $table_map = $this->ferretTables;

    $op_sub = PhutilSearchQueryCompiler::OPERATOR_SUBSTRING;
    $op_not = PhutilSearchQueryCompiler::OPERATOR_NOT;

    $where = array();
    $current_function = 'all';
    foreach ($this->ferretTokens as $fulltext_token) {
      $raw_token = $fulltext_token->getToken();
      $value = $raw_token->getValue();

      $function = $raw_token->getFunction();
      if ($function === null) {
        $function = $current_function;
      }
      $current_function = $function;

      $table_alias = $table_map[$function]['alias'];

      $is_not = ($raw_token->getOperator() == $op_not);

      if ($raw_token->getOperator() == $op_sub) {
        $is_substring = true;
      } else {
        $is_substring = false;
      }

      // If we're doing substring search, we just match against the raw corpus
      // and we're done.
      if ($is_substring) {
        if ($is_not) {
          $where[] = qsprintf(
            $conn,
            '(%T.rawCorpus NOT LIKE %~)',
            $table_alias,
            $value);
        } else {
          $where[] = qsprintf(
            $conn,
            '(%T.rawCorpus LIKE %~)',
            $table_alias,
            $value);
        }
        continue;
      }

      // Otherwise, we need to match against the term corpus and the normal
      // corpus, so that searching for "raw" does not find "strawberry".
      if ($raw_token->isQuoted()) {
        $is_quoted = true;
        $is_stemmed = false;
      } else {
        $is_quoted = false;
        $is_stemmed = true;
      }

      // Never stem negated queries, since this can exclude results users
      // did not mean to exclude and generally confuse things.
      if ($is_not) {
        $is_stemmed = false;
      }

      $term_constraints = array();

      $term_value = $engine->newTermsCorpus($value);
      if ($is_not) {
        $term_constraints[] = qsprintf(
          $conn,
          '(%T.termCorpus NOT LIKE %~)',
          $table_alias,
          $term_value);
      } else {
        $term_constraints[] = qsprintf(
          $conn,
          '(%T.termCorpus LIKE %~)',
          $table_alias,
          $term_value);
      }

      if ($is_stemmed) {
        $stem_value = $stemmer->stemToken($value);
        $stem_value = $engine->newTermsCorpus($stem_value);

        $term_constraints[] = qsprintf(
          $conn,
          '(%T.normalCorpus LIKE %~)',
          $table_alias,
          $stem_value);
      }

      if ($is_not) {
        $where[] = qsprintf(
          $conn,
          '(%Q)',
          implode(' AND ', $term_constraints));
      } else if ($is_quoted) {
        $where[] = qsprintf(
          $conn,
          '(%T.rawCorpus LIKE %~ AND (%Q))',
          $table_alias,
          $value,
          implode(' OR ', $term_constraints));
      } else {
        $where[] = qsprintf(
          $conn,
          '(%Q)',
          implode(' OR ', $term_constraints));
      }
    }

    if ($this->ferretQuery) {
      $query = $this->ferretQuery;

      $author_phids = $query->getParameter('authorPHIDs');
      if ($author_phids) {
        $where[] = qsprintf(
          $conn,
          'ft_doc.authorPHID IN (%Ls)',
          $author_phids);
      }

      $with_unowned = $query->getParameter('withUnowned');
      $with_any = $query->getParameter('withAnyOwner');

      if ($with_any && $with_unowned) {
        throw new PhabricatorEmptyQueryException(
          pht(
            'This query matches only unowned documents owned by anyone, '.
            'which is impossible.'));
      }

      $owner_phids = $query->getParameter('ownerPHIDs');
      if ($owner_phids && !$with_any) {
        if ($with_unowned) {
          $where[] = qsprintf(
            $conn,
            'ft_doc.ownerPHID IN (%Ls) OR ft_doc.ownerPHID IS NULL',
            $owner_phids);
        } else {
          $where[] = qsprintf(
            $conn,
            'ft_doc.ownerPHID IN (%Ls)',
            $owner_phids);
        }
      } else if ($with_unowned) {
        $where[] = qsprintf(
          $conn,
          'ft_doc.ownerPHID IS NULL');
      }

      if ($with_any) {
        $where[] = qsprintf(
          $conn,
          'ft_doc.ownerPHID IS NOT NULL');
      }

      $rel_open = PhabricatorSearchRelationship::RELATIONSHIP_OPEN;

      $statuses = $query->getParameter('statuses');
      $is_closed = null;
      if ($statuses) {
        $statuses = array_fuse($statuses);
        if (count($statuses) == 1) {
          if (isset($statuses[$rel_open])) {
            $is_closed = 0;
          } else {
            $is_closed = 1;
          }
        }
      }

      if ($is_closed !== null) {
        $where[] = qsprintf(
          $conn,
          'ft_doc.isClosed = %d',
          $is_closed);
      }
    }

    return $where;
  }

  protected function shouldGroupFerretResultRows() {
    return (bool)$this->ferretTokens;
  }


/* -(  Ngrams  )------------------------------------------------------------- */


  protected function withNgramsConstraint(
    PhabricatorSearchNgrams $index,
    $value) {

    if (strlen($value)) {
      $this->ngrams[] = array(
        'index' => $index,
        'value' => $value,
        'length' => count(phutil_utf8v($value)),
      );
    }

    return $this;
  }


  protected function buildNgramsJoinClause(AphrontDatabaseConnection $conn) {
    $flat = array();
    foreach ($this->ngrams as $spec) {
      $index = $spec['index'];
      $value = $spec['value'];
      $length = $spec['length'];

      if ($length >= 3) {
        $ngrams = $index->getNgramsFromString($value, 'query');
        $prefix = false;
      } else if ($length == 2) {
        $ngrams = $index->getNgramsFromString($value, 'prefix');
        $prefix = false;
      } else {
        $ngrams = array(' '.$value);
        $prefix = true;
      }

      foreach ($ngrams as $ngram) {
        $flat[] = array(
          'table' => $index->getTableName(),
          'ngram' => $ngram,
          'prefix' => $prefix,
        );
      }
    }

    // MySQL only allows us to join a maximum of 61 tables per query. Each
    // ngram is going to cost us a join toward that limit, so if the user
    // specified a very long query string, just pick 16 of the ngrams
    // at random.
    if (count($flat) > 16) {
      shuffle($flat);
      $flat = array_slice($flat, 0, 16);
    }

    $alias = $this->getPrimaryTableAlias();
    if ($alias) {
      $id_column = qsprintf($conn, '%T.%T', $alias, 'id');
    } else {
      $id_column = qsprintf($conn, '%T', 'id');
    }

    $idx = 1;
    $joins = array();
    foreach ($flat as $spec) {
      $table = $spec['table'];
      $ngram = $spec['ngram'];
      $prefix = $spec['prefix'];

      $alias = 'ngm'.$idx++;

      if ($prefix) {
        $joins[] = qsprintf(
          $conn,
          'JOIN %T %T ON %T.objectID = %Q AND %T.ngram LIKE %>',
          $table,
          $alias,
          $alias,
          $id_column,
          $alias,
          $ngram);
      } else {
        $joins[] = qsprintf(
          $conn,
          'JOIN %T %T ON %T.objectID = %Q AND %T.ngram = %s',
          $table,
          $alias,
          $alias,
          $id_column,
          $alias,
          $ngram);
      }
    }

    return $joins;
  }


  protected function buildNgramsWhereClause(AphrontDatabaseConnection $conn) {
    $where = array();

    foreach ($this->ngrams as $ngram) {
      $index = $ngram['index'];
      $value = $ngram['value'];

      $column = $index->getColumnName();
      $alias = $this->getPrimaryTableAlias();
      if ($alias) {
        $column = qsprintf($conn, '%T.%T', $alias, $column);
      } else {
        $column = qsprintf($conn, '%T', $column);
      }

      $tokens = $index->tokenizeString($value);
      foreach ($tokens as $token) {
        $where[] = qsprintf(
          $conn,
          '%Q LIKE %~',
          $column,
          $token);
      }
    }

    return $where;
  }


  protected function shouldGroupNgramResultRows() {
    return (bool)$this->ngrams;
  }


/* -(  Edge Logic  )--------------------------------------------------------- */


  /**
   * Convenience method for specifying edge logic constraints with a list of
   * PHIDs.
   *
   * @param const Edge constant.
   * @param const Constraint operator.
   * @param list<phid> List of PHIDs.
   * @return this
   * @task edgelogic
   */
  public function withEdgeLogicPHIDs($edge_type, $operator, array $phids) {
    $constraints = array();
    foreach ($phids as $phid) {
      $constraints[] = new PhabricatorQueryConstraint($operator, $phid);
    }

    return $this->withEdgeLogicConstraints($edge_type, $constraints);
  }


  /**
   * @return this
   * @task edgelogic
   */
  public function withEdgeLogicConstraints($edge_type, array $constraints) {
    assert_instances_of($constraints, 'PhabricatorQueryConstraint');

    $constraints = mgroup($constraints, 'getOperator');
    foreach ($constraints as $operator => $list) {
      foreach ($list as $item) {
        $this->edgeLogicConstraints[$edge_type][$operator][] = $item;
      }
    }

    $this->edgeLogicConstraintsAreValid = false;

    return $this;
  }


  /**
   * @task edgelogic
   */
  public function buildEdgeLogicSelectClause(AphrontDatabaseConnection $conn) {
    $select = array();

    $this->validateEdgeLogicConstraints();

    foreach ($this->edgeLogicConstraints as $type => $constraints) {
      foreach ($constraints as $operator => $list) {
        $alias = $this->getEdgeLogicTableAlias($operator, $type);
        switch ($operator) {
          case PhabricatorQueryConstraint::OPERATOR_AND:
            if (count($list) > 1) {
              $select[] = qsprintf(
                $conn,
                'COUNT(DISTINCT(%T.dst)) %T',
                $alias,
                $this->buildEdgeLogicTableAliasCount($alias));
            }
            break;
          case PhabricatorQueryConstraint::OPERATOR_ANCESTOR:
            // This is tricky. We have a query which specifies multiple
            // projects, each of which may have an arbitrarily large number
            // of descendants.

            // Suppose the projects are "Engineering" and "Operations", and
            // "Engineering" has subprojects X, Y and Z.

            // We first use `FIELD(dst, X, Y, Z)` to produce a 0 if a row
            // is not part of Engineering at all, or some number other than
            // 0 if it is.

            // Then we use `IF(..., idx, NULL)` to convert the 0 to a NULL and
            // any other value to an index (say, 1) for the ancestor.

            // We build these up for every ancestor, then use `COALESCE(...)`
            // to select the non-null one, giving us an ancestor which this
            // row is a member of.

            // From there, we use `COUNT(DISTINCT(...))` to make sure that
            // each result row is a member of all ancestors.
            if (count($list) > 1) {
              $idx = 1;
              $parts = array();
              foreach ($list as $constraint) {
                $parts[] = qsprintf(
                  $conn,
                  'IF(FIELD(%T.dst, %Ls) != 0, %d, NULL)',
                  $alias,
                  (array)$constraint->getValue(),
                  $idx++);
              }
              $parts = implode(', ', $parts);

              $select[] = qsprintf(
                $conn,
                'COUNT(DISTINCT(COALESCE(%Q))) %T',
                $parts,
                $this->buildEdgeLogicTableAliasAncestor($alias));
            }
            break;
          default:
            break;
        }
      }
    }

    return $select;
  }


  /**
   * @task edgelogic
   */
  public function buildEdgeLogicJoinClause(AphrontDatabaseConnection $conn) {
    $edge_table = PhabricatorEdgeConfig::TABLE_NAME_EDGE;
    $phid_column = $this->getApplicationSearchObjectPHIDColumn();

    $joins = array();
    foreach ($this->edgeLogicConstraints as $type => $constraints) {

      $op_null = PhabricatorQueryConstraint::OPERATOR_NULL;
      $has_null = isset($constraints[$op_null]);

      // If we're going to process an only() operator, build a list of the
      // acceptable set of PHIDs first. We'll only match results which have
      // no edges to any other PHIDs.
      $all_phids = array();
      if (isset($constraints[PhabricatorQueryConstraint::OPERATOR_ONLY])) {
        foreach ($constraints as $operator => $list) {
          switch ($operator) {
            case PhabricatorQueryConstraint::OPERATOR_ANCESTOR:
            case PhabricatorQueryConstraint::OPERATOR_AND:
            case PhabricatorQueryConstraint::OPERATOR_OR:
              foreach ($list as $constraint) {
                $value = (array)$constraint->getValue();
                foreach ($value as $v) {
                  $all_phids[$v] = $v;
                }
              }
              break;
          }
        }
      }

      foreach ($constraints as $operator => $list) {
        $alias = $this->getEdgeLogicTableAlias($operator, $type);

        $phids = array();
        foreach ($list as $constraint) {
          $value = (array)$constraint->getValue();
          foreach ($value as $v) {
            $phids[$v] = $v;
          }
        }
        $phids = array_keys($phids);

        switch ($operator) {
          case PhabricatorQueryConstraint::OPERATOR_NOT:
            $joins[] = qsprintf(
              $conn,
              'LEFT JOIN %T %T ON %Q = %T.src AND %T.type = %d
                AND %T.dst IN (%Ls)',
              $edge_table,
              $alias,
              $phid_column,
              $alias,
              $alias,
              $type,
              $alias,
              $phids);
            break;
          case PhabricatorQueryConstraint::OPERATOR_ANCESTOR:
          case PhabricatorQueryConstraint::OPERATOR_AND:
          case PhabricatorQueryConstraint::OPERATOR_OR:
            // If we're including results with no matches, we have to degrade
            // this to a LEFT join. We'll use WHERE to select matching rows
            // later.
            if ($has_null) {
              $join_type = 'LEFT';
            } else {
              $join_type = '';
            }

            $joins[] = qsprintf(
              $conn,
              '%Q JOIN %T %T ON %Q = %T.src AND %T.type = %d
                AND %T.dst IN (%Ls)',
              $join_type,
              $edge_table,
              $alias,
              $phid_column,
              $alias,
              $alias,
              $type,
              $alias,
              $phids);
            break;
          case PhabricatorQueryConstraint::OPERATOR_NULL:
            $joins[] = qsprintf(
              $conn,
              'LEFT JOIN %T %T ON %Q = %T.src AND %T.type = %d',
              $edge_table,
              $alias,
              $phid_column,
              $alias,
              $alias,
              $type);
            break;
          case PhabricatorQueryConstraint::OPERATOR_ONLY:
            $joins[] = qsprintf(
              $conn,
              'LEFT JOIN %T %T ON %Q = %T.src AND %T.type = %d
                AND %T.dst NOT IN (%Ls)',
              $edge_table,
              $alias,
              $phid_column,
              $alias,
              $alias,
              $type,
              $alias,
              $all_phids);
            break;
        }
      }
    }

    return $joins;
  }


  /**
   * @task edgelogic
   */
  public function buildEdgeLogicWhereClause(AphrontDatabaseConnection $conn) {
    $where = array();

    foreach ($this->edgeLogicConstraints as $type => $constraints) {

      $full = array();
      $null = array();

      $op_null = PhabricatorQueryConstraint::OPERATOR_NULL;
      $has_null = isset($constraints[$op_null]);

      foreach ($constraints as $operator => $list) {
        $alias = $this->getEdgeLogicTableAlias($operator, $type);
        switch ($operator) {
          case PhabricatorQueryConstraint::OPERATOR_NOT:
          case PhabricatorQueryConstraint::OPERATOR_ONLY:
            $full[] = qsprintf(
              $conn,
              '%T.dst IS NULL',
              $alias);
            break;
          case PhabricatorQueryConstraint::OPERATOR_AND:
          case PhabricatorQueryConstraint::OPERATOR_OR:
            if ($has_null) {
              $full[] = qsprintf(
                $conn,
                '%T.dst IS NOT NULL',
                $alias);
            }
            break;
          case PhabricatorQueryConstraint::OPERATOR_NULL:
            $null[] = qsprintf(
              $conn,
              '%T.dst IS NULL',
              $alias);
            break;
        }
      }

      if ($full && $null) {
        $full = $this->formatWhereSubclause($full);
        $null = $this->formatWhereSubclause($null);
        $where[] = qsprintf($conn, '(%Q OR %Q)', $full, $null);
      } else if ($full) {
        foreach ($full as $condition) {
          $where[] = $condition;
        }
      } else if ($null) {
        foreach ($null as $condition) {
          $where[] = $condition;
        }
      }
    }

    return $where;
  }


  /**
   * @task edgelogic
   */
  public function buildEdgeLogicHavingClause(AphrontDatabaseConnection $conn) {
    $having = array();

    foreach ($this->edgeLogicConstraints as $type => $constraints) {
      foreach ($constraints as $operator => $list) {
        $alias = $this->getEdgeLogicTableAlias($operator, $type);
        switch ($operator) {
          case PhabricatorQueryConstraint::OPERATOR_AND:
            if (count($list) > 1) {
              $having[] = qsprintf(
                $conn,
                '%T = %d',
                $this->buildEdgeLogicTableAliasCount($alias),
                count($list));
            }
            break;
          case PhabricatorQueryConstraint::OPERATOR_ANCESTOR:
            if (count($list) > 1) {
              $having[] = qsprintf(
                $conn,
                '%T = %d',
                $this->buildEdgeLogicTableAliasAncestor($alias),
                count($list));
            }
            break;
        }
      }
    }

    return $having;
  }


  /**
   * @task edgelogic
   */
  public function shouldGroupEdgeLogicResultRows() {
    foreach ($this->edgeLogicConstraints as $type => $constraints) {
      foreach ($constraints as $operator => $list) {
        switch ($operator) {
          case PhabricatorQueryConstraint::OPERATOR_NOT:
          case PhabricatorQueryConstraint::OPERATOR_AND:
          case PhabricatorQueryConstraint::OPERATOR_OR:
            if (count($list) > 1) {
              return true;
            }
            break;
          case PhabricatorQueryConstraint::OPERATOR_ANCESTOR:
            // NOTE: We must always group query results rows when using an
            // "ANCESTOR" operator because a single task may be related to
            // two different descendants of a particular ancestor. For
            // discussion, see T12753.
            return true;
          case PhabricatorQueryConstraint::OPERATOR_NULL:
          case PhabricatorQueryConstraint::OPERATOR_ONLY:
            return true;
        }
      }
    }

    return false;
  }


  /**
   * @task edgelogic
   */
  private function getEdgeLogicTableAlias($operator, $type) {
    return 'edgelogic_'.$operator.'_'.$type;
  }


  /**
   * @task edgelogic
   */
  private function buildEdgeLogicTableAliasCount($alias) {
    return $alias.'_count';
  }

  /**
   * @task edgelogic
   */
  private function buildEdgeLogicTableAliasAncestor($alias) {
    return $alias.'_ancestor';
  }


  /**
   * Select certain edge logic constraint values.
   *
   * @task edgelogic
   */
  protected function getEdgeLogicValues(
    array $edge_types,
    array $operators) {

    $values = array();

    $constraint_lists = $this->edgeLogicConstraints;
    if ($edge_types) {
      $constraint_lists = array_select_keys($constraint_lists, $edge_types);
    }

    foreach ($constraint_lists as $type => $constraints) {
      if ($operators) {
        $constraints = array_select_keys($constraints, $operators);
      }
      foreach ($constraints as $operator => $list) {
        foreach ($list as $constraint) {
          $value = (array)$constraint->getValue();
          foreach ($value as $v) {
            $values[] = $v;
          }
        }
      }
    }

    return $values;
  }


  /**
   * Validate edge logic constraints for the query.
   *
   * @return this
   * @task edgelogic
   */
  private function validateEdgeLogicConstraints() {
    if ($this->edgeLogicConstraintsAreValid) {
      return $this;
    }

    foreach ($this->edgeLogicConstraints as $type => $constraints) {
      foreach ($constraints as $operator => $list) {
        switch ($operator) {
          case PhabricatorQueryConstraint::OPERATOR_EMPTY:
            throw new PhabricatorEmptyQueryException(
              pht('This query specifies an empty constraint.'));
        }
      }
    }

    // This should probably be more modular, eventually, but we only do
    // project-based edge logic today.

    $project_phids = $this->getEdgeLogicValues(
      array(
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
      ),
      array(
        PhabricatorQueryConstraint::OPERATOR_AND,
        PhabricatorQueryConstraint::OPERATOR_OR,
        PhabricatorQueryConstraint::OPERATOR_NOT,
        PhabricatorQueryConstraint::OPERATOR_ANCESTOR,
      ));
    if ($project_phids) {
      $projects = id(new PhabricatorProjectQuery())
        ->setViewer($this->getViewer())
        ->setParentQuery($this)
        ->withPHIDs($project_phids)
        ->execute();
      $projects = mpull($projects, null, 'getPHID');
      foreach ($project_phids as $phid) {
        if (empty($projects[$phid])) {
          throw new PhabricatorEmptyQueryException(
            pht(
              'This query is constrained by a project you do not have '.
              'permission to see.'));
        }
      }
    }

    $op_and = PhabricatorQueryConstraint::OPERATOR_AND;
    $op_or = PhabricatorQueryConstraint::OPERATOR_OR;
    $op_ancestor = PhabricatorQueryConstraint::OPERATOR_ANCESTOR;

    foreach ($this->edgeLogicConstraints as $type => $constraints) {
      foreach ($constraints as $operator => $list) {
        switch ($operator) {
          case PhabricatorQueryConstraint::OPERATOR_ONLY:
            if (count($list) > 1) {
              throw new PhabricatorEmptyQueryException(
                pht(
                  'This query specifies only() more than once.'));
            }

            $have_and = idx($constraints, $op_and);
            $have_or = idx($constraints, $op_or);
            $have_ancestor = idx($constraints, $op_ancestor);
            if (!$have_and && !$have_or && !$have_ancestor) {
              throw new PhabricatorEmptyQueryException(
                pht(
                  'This query specifies only(), but no other constraints '.
                  'which it can apply to.'));
            }
            break;
        }
      }
    }

    $this->edgeLogicConstraintsAreValid = true;

    return $this;
  }


/* -(  Spaces  )------------------------------------------------------------- */


  /**
   * Constrain the query to return results from only specific Spaces.
   *
   * Pass a list of Space PHIDs, or `null` to represent the default space. Only
   * results in those Spaces will be returned.
   *
   * Queries are always constrained to include only results from spaces the
   * viewer has access to.
   *
   * @param list<phid|null>
   * @task spaces
   */
  public function withSpacePHIDs(array $space_phids) {
    $object = $this->newResultObject();

    if (!$object) {
      throw new Exception(
        pht(
          'This query (of class "%s") does not implement newResultObject(), '.
          'but must implement this method to enable support for Spaces.',
          get_class($this)));
    }

    if (!($object instanceof PhabricatorSpacesInterface)) {
      throw new Exception(
        pht(
          'This query (of class "%s") returned an object of class "%s" from '.
          'getNewResultObject(), but it does not implement the required '.
          'interface ("%s"). Objects must implement this interface to enable '.
          'Spaces support.',
          get_class($this),
          get_class($object),
          'PhabricatorSpacesInterface'));
    }

    $this->spacePHIDs = $space_phids;

    return $this;
  }

  public function withSpaceIsArchived($archived) {
    $this->spaceIsArchived = $archived;
    return $this;
  }


  /**
   * Constrain the query to include only results in valid Spaces.
   *
   * This method builds part of a WHERE clause which considers the spaces the
   * viewer has access to see with any explicit constraint on spaces added by
   * @{method:withSpacePHIDs}.
   *
   * @param AphrontDatabaseConnection Database connection.
   * @return string Part of a WHERE clause.
   * @task spaces
   */
  private function buildSpacesWhereClause(AphrontDatabaseConnection $conn) {
    $object = $this->newResultObject();
    if (!$object) {
      return null;
    }

    if (!($object instanceof PhabricatorSpacesInterface)) {
      return null;
    }

    $viewer = $this->getViewer();

    // If we have an omnipotent viewer and no formal space constraints, don't
    // emit a clause. This primarily enables older migrations to run cleanly,
    // without fataling because they try to match a `spacePHID` column which
    // does not exist yet. See T8743, T8746.
    if ($viewer->isOmnipotent()) {
      if ($this->spaceIsArchived === null && $this->spacePHIDs === null) {
        return null;
      }
    }

    $space_phids = array();
    $include_null = false;

    $all = PhabricatorSpacesNamespaceQuery::getAllSpaces();
    if (!$all) {
      // If there are no spaces at all, implicitly give the viewer access to
      // the default space.
      $include_null = true;
    } else {
      // Otherwise, give them access to the spaces they have permission to
      // see.
      $viewer_spaces = PhabricatorSpacesNamespaceQuery::getViewerSpaces(
        $viewer);
      foreach ($viewer_spaces as $viewer_space) {
        if ($this->spaceIsArchived !== null) {
          if ($viewer_space->getIsArchived() != $this->spaceIsArchived) {
            continue;
          }
        }
        $phid = $viewer_space->getPHID();
        $space_phids[$phid] = $phid;
        if ($viewer_space->getIsDefaultNamespace()) {
          $include_null = true;
        }
      }
    }

    // If we have additional explicit constraints, evaluate them now.
    if ($this->spacePHIDs !== null) {
      $explicit = array();
      $explicit_null = false;
      foreach ($this->spacePHIDs as $phid) {
        if ($phid === null) {
          $space = PhabricatorSpacesNamespaceQuery::getDefaultSpace();
        } else {
          $space = idx($all, $phid);
        }

        if ($space) {
          $phid = $space->getPHID();
          $explicit[$phid] = $phid;
          if ($space->getIsDefaultNamespace()) {
            $explicit_null = true;
          }
        }
      }

      // If the viewer can see the default space but it isn't on the explicit
      // list of spaces to query, don't match it.
      if ($include_null && !$explicit_null) {
        $include_null = false;
      }

      // Include only the spaces common to the viewer and the constraints.
      $space_phids = array_intersect_key($space_phids, $explicit);
    }

    if (!$space_phids && !$include_null) {
      if ($this->spacePHIDs === null) {
        throw new PhabricatorEmptyQueryException(
          pht('You do not have access to any spaces.'));
      } else {
        throw new PhabricatorEmptyQueryException(
          pht(
            'You do not have access to any of the spaces this query '.
            'is constrained to.'));
      }
    }

    $alias = $this->getPrimaryTableAlias();
    if ($alias) {
      $col = qsprintf($conn, '%T.spacePHID', $alias);
    } else {
      $col = 'spacePHID';
    }

    if ($space_phids && $include_null) {
      return qsprintf(
        $conn,
        '(%Q IN (%Ls) OR %Q IS NULL)',
        $col,
        $space_phids,
        $col);
    } else if ($space_phids) {
      return qsprintf(
        $conn,
        '%Q IN (%Ls)',
        $col,
        $space_phids);
    } else {
      return qsprintf(
        $conn,
        '%Q IS NULL',
        $col);
    }
  }

}
