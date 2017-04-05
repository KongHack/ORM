<?php
namespace GCWorld\ORM;

class ObjectForm
{
    protected $source   = null;
    protected $action   = null;
    protected $method   = null;
    protected $keys     = array();
    protected $fields   = array();
    protected $hidden   = array();
    protected $CKEditor = false;

    public $submit      = 'Submit';

    function __construct($source, $action = null, $method = 'POST')
    {
        $this->source   = $source;
        $this->action   = $action;  //Null action does not include an action
        $this->method   = $method;
        $this->keys         = array_keys(get_object_vars($source));
    }

    public function addField($key, $caption = '', $type = 'text', $params = array())
    {
        if ($key == 'hr') {
            $this->fields[] = array('caption'=>'<hr />', 'type'=>'hr');
        } elseif ($type == 'hidden') {
            $this->hidden[] = array('key'=>$key, 'value'=>$params['value']);
        } else {
            if (!property_exists($this->source, $key) && $key != 'caption') {
                throw new ORMException('Invalid Key - '.$key.'<br /><pre>'.print_r($this->keys, true).'</pre>');
            }
            $this->fields[] = array('key'=>$key, 'caption'=>$caption, 'type'=>$type, 'params'=>$params);
        }
    }

    public function printForm()
    {
        echo '<form '.($this->action!=null?'action="'.$this->action.'"':'').' method="',$this->method,'" role="form">';
        foreach ($this->hidden as $field) {
            echo '<input type="hidden" name="',$field['key'],'" value="',$field['value'],'" />';
        }

        foreach ($this->fields as $field) {
            $this->printField($field);
        }
        echo '
		<div class="form-group">
			<label></label>
			<input type="submit" value="',$this->submit,'" class="btn btn-default" />
		</div>';
        echo '</form>';
    }

    public function printField($field)
    {
        echo '
		<div class="form-group">
			<label>',$field['caption'],'</label>';

        switch ($field['type']) {
            case 'caption':
                echo '<p class="form-control-static">';
                echo $field['params']['caption'];
                echo '</p>';
                break;

            case 'text':
                echo '<input type="text" name="',$field['key'],'" value="',$this->source->$field['key'],'" class="form-control" />';
                break;

            case 'textarea':
                echo '<textarea name="',$field['key'],'" class="form-control" style="height:160px;">',$this->source->$field['key'],'</textarea>';
                break;

            case 'date':
                $val = ($this->source->$field['key'] > '0000-00-00' ? $this->source->$field['key'] : '');
                echo '<input type="text" class="datepicker form-control" name="',$field['key'],'" value="',$val,'" style="width:160px;" />';
                break;

            case 'number':
                echo '<input type="number" name="',$field['key'],'" value="',$this->source->$field['key'],'" step="any" class="form-control" />';
                break;

            case 'select':
                $val = $this->source->$field['key'];
                echo '<select name="',$field['key'],'" class="chzn-select form-control">';
                echo '<option></option>';
                if (is_array($field['params']['options'])) {
                    foreach ($field['params']['options'] as $k => $v) {
                        echo '<option value="',$k,'" ',($k==$val?'selected':''),'>',$v,'</option>';
                    }
                }
                echo '</select>';
                break;

            case 'multiselect':
                $val = $this->source->$field['key'];
                if (isset($field['params']['selected'])) {
                    $selected = $field['params']['selected'];
                } elseif (substr($val, 0, 1)=='*') {
                    $selected = explode('*', trim($val, '*'));
                } elseif (substr($val, 0, 1)=='{' || substr($val, 0, 1)=='[') {
                    $selected = json_decode($val, true);
                } else {
                    $selected = array();
                }
                if (!is_array($selected)) {
                    $selected = array();
                }
                
                unset($val);
                echo '<select name="',$field['key'],'[]" class="form-control chzn-select-deselect" multiple>';
                echo '<option></option>';
                if (is_array($field['params']['options'])) {
                    foreach ($field['params']['options'] as $k => $v) {
                        echo '<option value="',$k,'" ',(in_array($k, $selected)?'selected':''),'>',$v,'</option>';
                    }
                }
                echo '</select>';
                break;
            
            case 'html':    //For when a standard object just won't work, pass some html!
                echo '<p class="form-control-static">',$field['params']['html'],'</p>';
                break;

            case 'toggle':
                echo '<div class="radios">';
                echo '
					<input type="radio" name="',$field['key'],'" id="',$field['key'],'_0" value="0" ',($this->source->$field['key']==0?'checked="checked"':''),' />
					<label for="',$field['key'],'_0">No</label>
					<input type="radio" name="',$field['key'],'" id="',$field['key'],'_1" value="1" ',($this->source->$field['key']==1?'checked="checked"':''),' />
					<label for="',$field['key'],'_1">Yes</label>';
                echo '</div>';
                break;
            
            case 'CKEditor':
                echo '<div>';
                echo '<textarea name="',$field['key'],'" id="',$field['key'],'" style="width:400px; height:150px;">',$this->source->$field['key'],'</textarea>
					'.(isset($field['params']['caption'])?$field['params']['caption']:'');
                echo '</div>';
                echo '
				<script type="text/javascript">
					CKEDITOR.config.allowedContent = true;
					CKEDITOR.replace("',$field['key'],'");
				</script>';
                break;
            
            case 'hr':
                    //Do Nothing
                break;
        }
        echo '</div>';
    }
}
