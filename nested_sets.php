<?php
class NestedSets {
	
	protected $tbl;
	protected $DBH;
	
	protected function db() {
		try
			$DBH = new PDO('mysql:host=localhost;dbname=stroy_spravka;charset=utf8', 'stroy_spravka', 'stroy_spravka');
		catch(PDOException $e)
			die($e->getMessage());
			
		return $DBH;
	}
	
	public function __construct($tbl) {
		$this->tbl = $tbl;
		$this->DBH = $this->db();
	}
	
	// Создать дерево
	public function create($name = NULL) {
		$this->DBH->exec('DELETE FROM '.$this->tbl);
		$this->DBH->exec('ALTER TABLE '.$this->tbl.' AUTO_INCREMENT=0');
		
		$sql = '
			INSERT 
			INTO '.$this->tbl.' 
			VALUES(NULL, 1, 2, 1, :name)';
		
		$STH = $this->DBH->prepare($sql);
		$STH->bindParam(':name', $name);
		$STH->execute();
	}
	
	// Добавить узел
	public function add($id, $name = NULL) {
		$node = $this->get($id);
		$right_key = $node['right_key'];
		$level = $node['level'];
		
		$sql_update = '
			UPDATE '.$this->tbl.' 
			SET 
				right_key = right_key + 2, 
				left_key = IF(left_key > '.$right_key.', left_key + 2, left_key) 
			WHERE right_key >= '.$right_key;
		$this->DBH->exec($sql_update);
		
		$sql_insert = '
			INSERT 
			INTO '.$this->tbl.' 
			SET 
				left_key = '.$right_key.', 
				right_key = '.$right_key.' + 1, level = '.$level.' + 1, 
				name = :name';
		
		$STH = $this->DBH->prepare($sql_insert);
		$STH->bindParam(':name', $name);
		$STH->execute();
		return $this->DBH->lastInsertId();
	}
	
	// Удалить узел
	public function del($id) {
		$node = $this->get($id);
		$left_key = $node['left_key'];
		$right_key = $node['right_key'];
		
		$sql_delete = '
			DELETE 
			FROM '.$this->tbl.' 
			WHERE 
				left_key >= '.$left_key.' AND 
				right_key <= '.$right_key;
		
		$sql_update = '
			UPDATE '.$this->tbl.' 
			SET 
				left_key = IF(left_key > '.$left_key.', left_key - ('.$right_key.' - '.$left_key.' + 1), left_key), 
				right_key = right_key - ('.$right_key.' - '.$left_key.' + 1) 
			WHERE right_key > '.$right_key;
		
		$this->DBH->exec($sql_delete);
		$this->DBH->exec($sql_update);
	}
	
	// простое перемещение в другой узел
	public function move($id, $id_to) {
		$node = $this->get($id);
		$node_parent = self::parent_node($id);
		
		$node_to = $this->get($id_to);
		
		// перенос в текущем узле не реализован
		if($node_parent['id'] == $node_to['id']) {
			echo '==\n';
			return FALSE;
		}
		// перенос в корень не реализован
		if(!$id_to) {
			echo '0\n';
			return FALSE;
		}
		
		$left_key 	= $node['left_key'];
		$right_key 	= $node['right_key'];
		$level 		= $node['level'];
		
		$level_up	= $node_to['level'];
		
		$STH = $this->DBH->query('SELECT (right_key - 1) AS right_key FROM '.$this->tbl.' WHERE id = '.$id_to);
		
		$right_key_near = $STH->fetch(PDO::FETCH_ASSOC)['right_key'];
		
		$skew_level = $level_up - $level + 1;
		$skew_tree = $right_key- $left_key + 1;
		
		$STH = $this->DBH->query('SELECT id FROM '.$this->tbl.' WHERE left_key >= '.$left_key.' AND right_key <= '.$right_key);
		
		$id_edit = [];
		while($row = $STH->fetch(PDO::FETCH_ASSOC))
			$id_edit[] = $row['id'];
		
		$id_edit = implode(', ', $id_edit);
		
		if($right_key_near < $right_key) {
			//вышестоящие
			$skew_edit = $right_key_near - $left_key + 1;

			$sql[0] = '
				UPDATE '.$this->tbl.' 
				SET right_key = right_key + '.$skew_tree.' 
				WHERE 
					right_key < '.$left_key.' AND 
					right_key > '.$right_key_near;

			$sql[1] = '
				UPDATE '.$this->tbl.' 
				SET left_key = left_key + '.$skew_tree.' 
				WHERE 
					left_key < '.$left_key.' AND 
					left_key > '.$right_key_near;

			$sql[2] = '
				UPDATE '.$this->tbl.' 
				SET left_key = left_key + '.$skew_edit.', 
					right_key = right_key + '.$skew_edit.', 
					level = level + '.$skew_level.' 
				WHERE id IN ('.$id_edit.')';
			
		} else {
			//нижестоящие
			$skew_edit = $right_key_near - $left_key +1 - $skew_tree;
			
			$sql[0] = '
				UPDATE '.$this->tbl.' 
				SET right_key = right_key - '.$skew_tree.' 
				WHERE 
					right_key > '.$right_key.' AND 
					right_key <= '.$right_key_near;
				
			$sql[1] = '
				UPDATE '.$this->tbl.' 
				SET left_key = left_key - '.$skew_tree.' 
				WHERE 
					left_key > '.$left_key.' AND 
					left_key <= '.$right_key_near;
				
			$sql[2] = '
				UPDATE '.$this->tbl.' 
				SET left_key = left_key + '.$skew_edit.', 
					right_key = right_key + '.$skew_edit.', 
					level = level + '.$skew_level.' 
				WHERE id IN ('.$id_edit.')';
		}

		$this->DBH->exec($sql[0]);
		$this->DBH->exec($sql[1]);
		$this->DBH->exec($sql[2]);
	}
	
	// RETURNS
	
	// Получить узел
	public function get($id) {
		$sql = '
			SELECT * 
			FROM '.$this->tbl.' 
			WHERE id = :id';
		
		$STH = $this->DBH->prepare($sql);
		$STH->bindParam(':id', $id);
		$STH->execute();
		return $STH->fetch(PDO::FETCH_ASSOC);
	}

	// Дерево
	public function tree($parent_node = TRUE) {
		if($parent_node) {
			$sql = '
			SELECT id, name, level 
			FROM '.$this->tbl.' 
			ORDER BY left_key';
		} else {
			$sql = '
			SELECT id, name, level 
			FROM '.$this->tbl.' 
			WHERE id != 1
			ORDER BY left_key';
		}
		
		$STH = $this->DBH->query($sql);
		$r = [];
		while($row = $STH->fetch(PDO::FETCH_ASSOC))
			$r[$row['id']] = $row;
		return $r;
	}
	
	// Подчиненная ветка
	public function child_branch($id, $parent_node = TRUE) {
		$node = $this->get($id);
		$left_key = $node['$left_key'];
		$right_key = $node['roght_key'];
		
		if($parent_node) {
			$sql = '
			SELECT id, name, level 
			FROM '.$this->tbl.' 
			WHERE 
				left_key >= '.$left_key.' AND 
				right_key <= '.$right_key.' 
			ORDER BY left_key';
		} else {
			$sql = '
			SELECT 
				id, name, level 
			FROM 
				'.$this->tbl.' 
			WHERE 
				left_key >= '.$left_key.' 
				AND 
				right_key <= '.$right_key.' 
				AND
				id != :id
			ORDER BY 
				left_key';
		}
		
		$STH = $this->DBH->prepare($sql);
		if(!$parent_node)
			$STH->bindParam(':id', $id);
		$STH->execute();
		
		$r = [];
		while($row = $STH->fetch(PDO::FETCH_ASSOC))
			$r[$row['id']] = $row;
		return $r;
	}
	
	// Подчиненные узлы
	public function child($id, $parent_node = TRUE) {
		$node = $this->get($id);
		$left_key = $node['left_key'];
		$right_key = $node['right_key'];
		$level = $node['level'] + 1;
		
		if($parent_node) {
			$sql = '
			SELECT id, name, level 
			FROM '.$this->tbl.' 
			WHERE 
				left_key >= '.$left_key.' AND 
				right_key <= '.$right_key.' AND
				level <= '.$level.'
			ORDER BY left_key';
		} else {
			$sql = '
			SELECT id, name, level 
			FROM '.$this->tbl.' 
			WHERE 
				left_key >= '.$left_key.' AND 
				right_key <= '.$right_key.' AND
				level <= '.$level.' AND
				id != :id
			ORDER BY left_key';
		}
		
		$STH = $this->DBH->prepare($sql);
		if(!$parent_node)
			$STH->bindParam(':id', $id);
		$STH->execute();
		$r = [];
		while($row = $STH->fetch(PDO::FETCH_ASSOC))
			$r[$row['id']] = $row;
		return $r;
	}
	
	// Родительская ветка
	public function parent_branch($id) {
		$node = $this->get($id);
		$left_key = $node['left_key'];
		$right_key = $node['right_key'];
		
		$sql = '
			SELECT id, name, level 
			FROM '.$this->tbl.' 
			WHERE 
				left_key <= '.$left_key.' AND 
				right_key >= '.$right_key.' 
			ORDER BY left_key';
		
		$STH = $this->DBH->query($sql);
		$r = [];
		while($row = $STH->fetch(PDO::FETCH_ASSOC))
			$r[$row['id']] = $row;
		return $r;		
	}
	
	// Родительский узел
	public function parent_node($id) {
		$node = $this->get($id);
		$left_key = $node['left_key'];
		$right_key = $node['right_key'];
		$level = $node['level'] - 1;

		$sql = '
			SELECT id, name, level 
			FROM '.$this->tbl.' 
			WHERE 
				left_key <= '.$left_key.' AND 
				right_key >= '.$right_key.' AND
				level = '.$level.'
			ORDER BY left_key';
		
		$STH = $this->DBH->query($sql);
		return $STH->fetch(PDO::FETCH_ASSOC);
	}
	
	// Ветка
	public function branch($id) {
		$node = $this->get($id);
		$left_key = $node['left_key'];
		$right_key = $node['right_key'];

		$sql = '
			SELECT id, name, level 
			FROM '.$this->tbl.' 
			WHERE 
				right_key > '.$left_key.' AND 
				left_key < '.$right_key.' 
			ORDER BY left_key';
		
		$STH = $this->DBH->query($sql);
		$r = [];
		while($row = $STH->fetch(PDO::FETCH_ASSOC))
			$r[$row['id']] = $row;
		return $r;
	}
}

/*
 * NESTED SETS
 * 
 * МЕТОДЫ:
 * 
 * Управления:
 * 
 * $id - id текушего узла
 * $name - имя
 * $id_to - узел в который производится перемещение
 *  
 * create($name) - создать дерево
 * 
 * add($id, $name) - добавить узел с именем $name 
 * в родительский узел с id - $id
 * 
 * del($id) - удалить узел
 * 
 * move($id, $id_to) - простое перемещение узла в родительский узел 
 * узел добавляется последним при наличии в родительского узла 
 * подчиненных узлов.
 * Сортировка, перемещение в корень - не реализованы.
 * 
 * 
 * Получания:
 * результаты отдаются в виде массива
 * 
 * $id - id текушего узла
 * $parent_node - родительский узел
 * 
 * tree($parent_node = TRUE) - получить все дерево, 
 * FALSE - не отдавать родительский нод
 * 
 * branch($id) - получить ветку в которой участвует узел
 * 
 * parent_node($id) - получить родительский узел узла
 * 
 * parent_branch($id) - получить родительскую ветку узла
 * 
 * child($id, $parent_node = TRUE) - получить подчиненные узлы, 
 * FALSE - не отдавать родительский нод
 * 
 * child_branch($id, $parent_node = TRUE) - получить все подчиненные узлы, 
 * FALSE - не отдавать родительский нод
 * 
 * get($id) - получить узел
 * 
 * 
 * Автор: Александр Каплий
 */


$ns = new NestedSets('cat');
print_r($ns->tree());
?>
