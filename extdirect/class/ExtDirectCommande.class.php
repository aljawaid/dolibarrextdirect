<?PHP

/*
 * Copyright (C) 2013-2014  Francis Appels <francis.appels@z-application.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *  \file       htdocs/extdirect/class/ExtDirectCommande.class.php
 *  \brief      Sencha Ext.Direct orders remoting class
 */

require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';
dol_include_once('/extdirect/class/extdirect.class.php');

/** ExtDirectCommande class
 * 
 * Orders Class to with CRUD methods to connect to Extjs or sencha touch using Ext.direct connector
 */
class ExtDirectCommande extends Commande
{
    private $_user;
    private $_orderConstants = ['STOCK_MUST_BE_ENOUGH_FOR_ORDER','STOCK_CALCULATE_ON_VALIDATE_ORDER'];
    
    /** Constructor
     *
     * @param string $login user name
     */
    public function __construct($login) 
    {
        global $langs,$db,$user;
        
        if (!empty($login)) {
            if ($user->fetch('', $login)>0) {
                $user->getrights();
                $this->_user = $user;  //commande.class uses global user
                if (isset($this->_user->conf->MAIN_LANG_DEFAULT) && ($this->_user->conf->MAIN_LANG_DEFAULT != 'auto')) {
                    $langs->setDefaultLang($this->_user->conf->MAIN_LANG_DEFAULT);
                }
                $langs->load("orders");
                $langs->load("sendings"); // for shipment methods
                parent::__construct($db);
            }
        }
    }
    
    /**
     *	Load order related constants
     * 
     *	@param			stdClass	$params		filter with elements
     *		constant	name of specific constant
     *
     *	@return			stdClass result data with specific constant value
     */
    public function readConstants(stdClass $params)
    {
    	if (!isset($this->db)) return CONNECTERROR;
    	if (!isset($this->_user->rights->commande->lire)) return PERMISSIONERROR;
    	
    	$results = ExtDirect::readConstants($this->db, $params, $this->_user, $this->_orderConstants);
    	
    	return $results;
    }
    
    /**
     *    Load order from database into memory
     *
     *    @param    stdClass    $params     filter with elements:
     *      id                  Id of order to load
     *      ref                 ref, ref_int
     *      
     *    @return     stdClass result data or error number
     */
    public function readOrder(stdClass $params)
    {
        if (!isset($this->db)) return CONNECTERROR;
        if (!isset($this->_user->rights->commande->lire)) return PERMISSIONERROR;
        $myUser = new User($this->db);
        $mySociete = new Societe($this->db);
        $results = array();
        $row = new stdClass;
        $id = 0;
        $ref = '';
        $ref_ext = '';
        $ref_int = '';
        $orderstatus_ids = array();
        
        if (isset($params->filter)) {
            foreach ($params->filter as $key => $filter) {
                if ($filter->property == 'id') $id=$filter->value;
                else if ($filter->property == 'ref') $ref=$filter->value;
                else if ($filter->property == 'ref_int') $ref_int=$filter->value;
                else if ($filter->property == 'orderstatus_id') array_push($orderstatus_ids,$filter->value);
            }
        }
        
        if (($id > 0) || ($ref != '') || ($ref_int != '')) {
            if (($result = $this->fetch($id, $ref, $ref_ext, $ref_int)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
            if (!$this->error) {
                $row->id = $this->id ;
                //! Ref
                $row->ref= $this->ref;
                $row->ref_customer= $this->ref_client;
                $row->customer_id = $this->socid;
                if ($mySociete->fetch($this->socid)>0) {
                    $row->customer_name = $mySociete->name;
                }
                //! -1 for cancelled, 0 for draft, 1 for validated, 2 for send, 3 for closed
                $row->orderstatus_id = $this->statut;
                $row->orderstatus = $this->getLibStatut(1);
                $row->note_private = $this->note_private;
                $row->note_public = $this->note_public;
                $row->user_id = $this->user_author_id;
                if ($myUser->fetch($this->user_author_id)>0) {
                    $row->user_name = $myUser->firstname . ' ' . $myUser->lastname;
                }
                $row->order_date = $this->date_commande;
                $row->deliver_date= $this->date_livraison;
                $row->availability_id = $this->availability_id;
                $row->availability_code = $this->availability_code;
                $row->reduction_percent = $this->remise_percent;
                $row->payment_condition_id = $this->cond_reglement_id;
                $row->payment_type_id = $this->mode_reglement_id;
                $row->total_net = $this->total_ht;
                $row->total_tax = $this->total_tva;
                $row->total_inc = $this->total_ttc;
                $row->shipping_method_id = $this->shipping_method_id;
				$row->incoterms_id = $this->fk_incoterms;
				$row->location_incoterms = $this->location_incoterms;
				$row->customer_type = $mySociete->typent_code;
	            if ($this->remise == 0) {
	            	$row->reduction = 0;
	            	foreach ($this->lines as $line) {
	            		if ($line->remise_percent > 0) {
		            		$tabprice = calcul_price_total($line->qty, $line->subprice, 0, $line->tva_tx, $line->total_localtax1, $line->total_localtax2, 0, 'HT', $line->info_bits, $line->product_type);	
							$noDiscountHT = $tabprice[0];
							$noDiscountTTC = $tabprice[2];
			            	if ($row->customer_type == 'TE_PRIVATE') {
			            		$row->reduction += round($noDiscountTTC - $line->total_ttc, 2);
			            	} else {
			            		$row->reduction += round($noDiscountHT - $line->total_ht, 2);
			            	}
	            		}
	            	}	            		                
	            } else {
	            	$row->reduction = $this->remise;
	            }
	            
                if (empty($orderstatus_ids)) {
                    array_push($results, $row);
                } else {
                    foreach($orderstatus_ids as $orderstatus_id) {
                        if ($orderstatus_id == $row->orderstatus_id) {
                            array_push($results, $row);
                        }
                    }
                }
            }
        }
        
        return $results;
    }


    /**
     * Ext.direct method to Create Order
     * 
     * @param unknown_type $param object or object array with product model(s)
     * @return Ambigous <multitype:, unknown_type>|unknown
     */
    public function createOrder($param) 
    {
        if (!isset($this->db)) return CONNECTERROR;
        if (!isset($this->_user->rights->commande->creer)) return PERMISSIONERROR;
        $notrigger=0;
        $paramArray = ExtDirect::toArray($param);
        
        foreach ($paramArray as &$params) {
            // prepare fields
            $this->prepareOrderFields($params);
            if (($result = $this->create($this->_user, $notrigger)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
            
            $params->id=$this->id;
        }

        if (is_array($param)) {
            return $paramArray;
        } else {
            return $params;
        }
    }

    /**
     * Ext.direct method to update order
     * 
     * @param unknown_type $param object or object array with order model(s)
     * @return Ambigous <multitype:, unknown_type>|unknown
     */
    public function updateOrder($param) 
    {
        global $conf, $langs, $mysoc;
    	
    	if (!isset($this->db)) return CONNECTERROR;
        if (!isset($this->_user->rights->commande->creer)) return PERMISSIONERROR;
        $paramArray = ExtDirect::toArray($param);

        foreach ($paramArray as &$params) {
            // prepare fields
            if ($params->id) {
                $this->id = $params->id;
                $this->prepareOrderFields($params);
                // update
                switch ($params->orderstatus_id) {
                    case -1:
                        $result = $this->cancel();
                        break;
                    case 0:
                        $result = $this->set_draft($this->_user);
                        break;
                    case 1:
                    	// set global $mysoc required to set pdf sender
                    	$mysoc = new Societe($this->db);
		                $mysoc->setMysoc($conf);
                    	if ($params->warehouse_id > 0) {
                    		$warehouseId = $params->warehouse_id;
                    	} else {
                    		$warehouseId = 0;
                    	}
                    	$result = $this->valid($this->_user, $warehouseId);
                    	// PDF generating
                        if (($result >= 0) && empty($conf->global->MAIN_DISABLE_PDF_AUTOUPDATE)) {
                        	if (($result = $this->fetch($this->id)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                        	$hidedetails = (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS) ? 1 : 0);
							$hidedesc = (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_DESC) ? 1 : 0);
							$hideref = (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_HIDE_REF) ? 1 : 0);
                        	$outputlangs = $langs;
							if ($conf->global->MAIN_MULTILANGS)	{
								$this->fetch_thirdparty();
								$newlang = $this->thirdparty->default_lang;
								$outputlangs = new Translate("", $conf);
								$outputlangs->setDefaultLang($newlang);
							}
							$this->generateDocument($this->modelpdf, $outputlangs, $hidedetails, $hidedesc, $hideref);
                        }
                        break;
                    case 3:
                        $result = $this->cloture($this->_user);
                        break;
                    default:
                        break;   
                }
                
                if ($result < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                if (($result = $this->set_date($this->_user, $this->date_commande)) < 0) return $result;
                if (($result = $this->set_date_livraison($this->_user, $this->date_livraison)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                if (($this->availability_id > 0) && 
                    ($result = $this->set_availability($this->_user, $this->availability_id)) < 0)  return ExtDirect::getDolError($result, $this->errors, $this->error);
                if (isset($this->remise_percent) && 
                    ($result = $this->set_remise($this->_user, $this->remise_percent)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                if (isset($this->cond_reglement_id) &&
                    ($result = $this->setPaymentTerms($this->cond_reglement_id)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                if (isset($this->mode_reglement_id) &&
                    ($result = $this->setPaymentMethods($this->mode_reglement_id)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                if (isset($this->shipping_method_id) &&
                	($result = $this->setShippingMethod($this->shipping_method_id)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                if (isset($this->fk_incoterms) &&
                	($result = $this->setIncoterms($this->fk_incoterms, $this->location_incoterms)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                
            } else {
                return PARAMETERERROR;
            }
        }
        if (is_array($param)) {
            return $paramArray;
        } else {
            return $params;
        }
    }

    /**
     * Ext.direct method to destroy order
     * 
     * @param unknown_type $param object or object array with order model(s)
     * @return Ambigous <multitype:, unknown_type>|unknown
     */
    public function destroyOrder($param) 
    {
        if (!isset($this->db)) return CONNECTERROR;
        if (!isset($this->_user->rights->commande->supprimer)) return PERMISSIONERROR;
        $paramArray = ExtDirect::toArray($param);

        foreach ($paramArray as &$params) {
            // prepare fields
            if ($params->id) {
                $this->id = $params->id;
                // delete 
                if (($result = $this->delete($this->_user)) < 0)    return ExtDirect::getDolError($result, $this->errors, $this->error);
            } else {
                return PARAMETERERROR;
            }
        }

        if (is_array($param)) {
            return $paramArray;
        } else {
            return $params;
        }
    }
        
    /**
     * private method to copy order fields into dolibarr object
     * 
     * @param stdclass $params object with fields
     * @return null
     */
    private function prepareOrderFields($params) 
    {
        isset($params->ref) ? ( $this->ref = $params->ref ) : ( $this->ref = null);
        isset($params->ref_int) ? ( $this->ref_int = $params->ref_int ) : ( $this->ref_int = null);
        isset($params->ref_customer) ? ( $this->ref_client = $params->ref_customer) : ( $this->ref_client = null);
        isset($params->customer_id) ? ( $this->socid = $params->customer_id) : ( $this->socid = null);
        //isset($params->orderstatus_id) ? ( $this->statut = $params->orderstatus_id) : ($this->statut  = 0);
        isset($params->note_private) ? ( $this->note_private =$params->note_private) : ( $this->note_private= null);
        isset($params->note_public) ? ( $this->note_public = $params->note_public ) : ($this->note_public = null);      
        isset($params->user_id) ? ( $this->user_author_id = $params->user_id) : ($this->user_author_id = null); 
        isset($params->order_date) ? ( $this->date_commande =$params->order_date) : ($this->date_commande = null);
        isset($params->deliver_date) ? ( $this->date_livraison =$params->deliver_date) : ($this->date_livraison = null);
        isset($params->availability_id) ? ( $this->availability_id =$params->availability_id) : ($this->availability_id = null);
        isset($params->availability_code) ? ( $this->availability_code =$params->availability_code) : ($this->availability_code = null);
        isset($params->reduction_percent) ? ($this->remise_percent = $params->reduction_percent) : null;
        isset($params->payment_condition_id) ? ($this->cond_reglement_id = $params->payment_condition_id) : null;
        isset($params->payment_type_id) ? ($this->mode_reglement_id = $params->payment_type_id) : null;
        isset($params->shipping_method_id) ? ($this->shipping_method_id = $params->shipping_method_id) : null;
        isset($params->incoterms_id) ? ($this->fk_incoterms = $params->incoterms_id) : null;
        isset($params->location_incoterms) ? ($this->location_incoterms = $params->location_incoterms) : null;
    } 
    
    /**
     * public method to read a list of orders
     *
     * @param stdClass $params to filter on order status and ref
     * @return     stdClass result data or error number
     */
    public function readOrderList(stdClass $params) 
    {
        global $conf;
        
        if (!isset($this->db)) return CONNECTERROR;
        $results = array();
        $row = new stdClass;
        $myUser = new User($this->db);
        $statusFilterCount = 0;
        $ref = null;
        $contactTypeId = 0;
        $barcode = null;
        if (isset($params->filter)) {
            foreach ($params->filter as $key => $filter) {
                if ($filter->property == 'orderstatus_id') $orderstatus_id[$statusFilterCount++]=$filter->value;
                if ($filter->property == 'ref') $ref=$filter->value;
                if ($filter->property == 'contacttype_id') $contactTypeId = $filter->value;
                if ($filter->property == 'contact_id') $contactId = $filter->value;
                if ($filter->property == 'barcode') $barcode = $filter->value;
            }
        }
        
        $sql = "SELECT DISTINCT s.nom, s.rowid AS socid, c.rowid, c.ref, c.fk_statut, c.ref_int, c.fk_availability, ea.status, s.price_level, c.ref_client, c.fk_user_author, c.total_ttc, c.date_livraison";
        $sql.= " FROM ".MAIN_DB_PREFIX."commande as c";
        $sql.= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON c.fk_soc = s.rowid";
        if ($barcode) {
        	$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."commandedet as cd ON c.rowid = cd.fk_commande";
        	$sql.= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = cd.fk_product";
        }
        $sql.= " LEFT JOIN ".MAIN_DB_PREFIX."element_contact as ec ON c.rowid = ec.element_id";
        $sql.= " LEFT JOIN ("; // get latest extdirect activity status for commande to check if locked
        $sql.= "   SELECT ma.activity_id, ma.maxrow AS rowid, ea.status";
        $sql.= "   FROM (";
        $sql.= "    SELECT MAX( rowid ) AS maxrow, activity_id";
        $sql.= "    FROM ".MAIN_DB_PREFIX."extdirect_activity";
        $sql.= "    GROUP BY activity_id";
        $sql.= "   ) AS ma, ".MAIN_DB_PREFIX."extdirect_activity AS ea";
        $sql.= "   WHERE ma.maxrow = ea.rowid";
        $sql.= " ) AS ea ON c.rowid = ea.activity_id";
        $sql.= " WHERE c.entity IN (".getEntity('commande', 1).')';
        $sql.= " AND c.fk_soc = s.rowid";
        
        
        if ($statusFilterCount>0) {
            $sql.= " AND ( ";
            foreach ($orderstatus_id as $key => $fk_statut) {
                $sql .= "c.fk_statut = ".$fk_statut;
                if ($key < ($statusFilterCount-1)) $sql .= " OR ";
            }
            $sql.= ")";
        }
        if ($ref) {
            $sql.= " AND c.ref = '".$ref."'";
        }
        if ($contactTypeId > 0) {
            $sql.= " AND ec.fk_c_type_contact = ".$contactTypeId;
            $sql.= " AND ec.fk_socpeople = ".$contactId;
        }
    	if ($barcode) {
            $sql.= " AND (p.barcode = '".$this->db->escape($barcode)."' OR c.ref = '".$this->db->escape($barcode)."' OR c.ref_client = '".$this->db->escape($barcode)."')";
        }
        $sql .= " ORDER BY c.date_commande DESC";
        
        dol_syslog(get_class($this)."::readOrderList sql=".$sql, LOG_DEBUG);
        $resql=$this->db->query($sql);
        
        if ($resql) {
            $num=$this->db->num_rows($resql);
            for ($i = 0;$i < $num; $i++) {
                $obj = $this->db->fetch_object($resql);
                $row = null;
                $row->id            = (int) $obj->rowid;
                $row->customer      = $obj->nom;
                $row->customer_id   = (int) $obj->socid;
                $row->ref           = $obj->ref;
                $row->ref_int           = $obj->ref_int;
                $row->orderstatus_id= (int) $obj->fk_statut;
                $row->orderstatus   = $this->LibStatut($obj->fk_statut, false, 1);
                $row->availability_id = $obj->fk_availability;
                $row->status        = $obj->status;
                $row->customer_price_level = (int) $obj->price_level;
                $row->ref_customer  = $obj->ref_client;
            	$row->user_id 		= $obj->fk_user_author;
                if ($myUser->fetch($row->user_id)>0) {
                    $row->user_name = $myUser->firstname . ' ' . $myUser->lastname;
                }
                $row->total_inc		= $obj->total_ttc;
                $row->deliver_date  = $this->db->jdate($obj->date_livraison);
                array_push($results, $row);
            }
            $this->db->free($resql);
            return $results;
        } else {
            $error="Error ".$this->db->lasterror();
            dol_syslog(get_class($this)."::readOrdelList ".$error, LOG_ERR);
            return SQLERROR;
        }   
    }
    
    /**
     * public method to read a list of orderstatusses
     *
     * @return     stdClass result data or error number
     */
    public function readOrderStatus() 
    {
        if (!isset($this->db)) return CONNECTERROR;
        $results = array();
        $row = new stdClass;
        $statut = -1;
        while (($result = $this->LibStatut($statut, false, 1)) != '') {
            $row = null;
            $row->id = $statut++;
            $row->status = $result;
            array_push($results, $row);
        }
        return $results;
    }
    
    /**
     * public method to read a list of contac types
     *
     * @return     stdClass result data or error number
     */
    public function readContactTypes()
    {
        if (!isset($this->db)) return CONNECTERROR;
        $results = array();
        $row = new stdClass;
        if (! is_array($result = $this->liste_type_contact())) return ExtDirect::getDolError($result, $this->errors, $this->error);
        // add empty type
        $row->id = 0;
        $row->label = '';
        array_push($results, $row);
        foreach ($result as $id => $label) {
            $row = null;
            $row->id = $id;
            $row->label = html_entity_decode($label);
            array_push($results, $row);
        }
        return $results;
    }
    
    /**
     * public method to read a list of availability codes
     *
     * @return     stdClass result data or error number
     */

    function readAvailabilityCodes()
    {
        global $langs;

        if (!isset($this->db)) return CONNECTERROR;
        $results = array();
        $row = new stdClass;

        $sql = 'SELECT ca.rowid, ca.code , ca.label';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'c_availability as ca';
        $sql .= ' WHERE ca.active = 1';
        $sql .= ' ORDER BY ca.rowid';
        
        dol_syslog(get_class($this)."::readAvailabilityCodes sql=".$sql, LOG_DEBUG);
        $resql=$this->db->query($sql);
        
        if ($resql) {
            $num=$this->db->num_rows($resql);

            for ($i = 0;$i < $num; $i++) {
                $obj = $this->db->fetch_object($resql);
                $row = null;

                $row->id = $obj->rowid;
                $transcode=$langs->transnoentities($obj->code);
                $label=($transcode!=null?$transcode:$obj->label);
                $row->code = $obj->code;
                $row->label = $label;
                array_push($results, $row);
            }

            $this->db->free($resql);
            return $results;
        } else {
            $error="Error ".$this->db->lasterror();
            dol_syslog(get_class($this)."::readAvailabilityCodes ".$error, LOG_ERR);
            return SQLERROR;
        }
    }
    
	/**
     * public method to read a list of shipping modes
     *
     * @return     stdClass result data or error number
     */

    function readShipmentModes()
    {
        global $langs;

        if (!isset($this->db)) return CONNECTERROR;
        $results = array();
        $row = new stdClass;
		if (ExtDirect::checkDolVersion(0,'3.7','')) {
			$sql = 'SELECT csm.rowid, csm.code , csm.libelle as label';
	        $sql .= ' FROM '.MAIN_DB_PREFIX.'c_shipment_mode as csm';
	        $sql .= ' WHERE csm.active > 0';
	        $sql .= ' ORDER BY csm.rowid';
	        
	        dol_syslog(get_class($this)."::readShipmentModes sql=".$sql, LOG_DEBUG);
	        $resql=$this->db->query($sql);
	        
	        if ($resql) {
	            $num=$this->db->num_rows($resql);
	
	            for ($i = 0;$i < $num; $i++) {
	                $obj = $this->db->fetch_object($resql);
	                $row = null;
	
	                $row->id = $obj->rowid;
	                $transcode=$langs->transnoentities("SendingMethod".strtoupper($obj->code));
	                $label=($transcode!=null?$transcode:$obj->label);
	                $row->code = $obj->code;
	                $row->label = $label;
	                array_push($results, $row);
	            }
	
	            $this->db->free($resql);
	            return $results;
	        } else {
	            $error="Error ".$this->db->lasterror();
	            dol_syslog(get_class($this)."::readShipmentModes ".$error, LOG_ERR);
	            return SQLERROR;
	        }
		} else {
			return $results;
		}
    }
    
	/**
     * public method to read a list of incoterm codes
     *
     * @return     stdClass result data or error number
     */

    function readIncotermCodes()
    {
        if (!isset($this->db)) return CONNECTERROR;
        $results = array();
        $row = new stdClass;
        if (ExtDirect::checkDolVersion(0,'3.8','')) {
	        $sql = 'SELECT ci.rowid, ci.code';
	        $sql .= ' FROM '.MAIN_DB_PREFIX.'c_incoterms as ci';
	        $sql .= ' WHERE ci.active > 0';
	        $sql .= ' ORDER BY ci.rowid';
	        
	        dol_syslog(get_class($this)."::readIncotermCodes sql=".$sql, LOG_DEBUG);
	        $resql=$this->db->query($sql);
	        
	        if ($resql) {
	            $num=$this->db->num_rows($resql);
	
	            for ($i = 0;$i < $num; $i++) {
	                $obj = $this->db->fetch_object($resql);
	                $row = null;
	
	                $row->id = $obj->rowid;
	                $row->code = $obj->code;
	                array_push($results, $row);
	            }
	
	            $this->db->free($resql);
	            return $results;
	        } else {
	            $error="Error ".$this->db->lasterror();
	            dol_syslog(get_class($this)."::readIncotermCodes ".$error, LOG_ERR);
	            return SQLERROR;
	        }
        }  else {
			return $results;
		}
    }
    
    /**
     *    Load orderlines from database into memory
     *
     *    @param    stdClass    $params     filter with elements:
     *                              order_id Id of order to load lines from
     *                              warehouse_id 
     *                                  warehouse_id x to get stock of 
     *                                  warehouse_id -1 will get total stock
     *                                  no warehouse_id will split lines in stock by warehouse
     *                              photo_size string with foto size 'mini' or 'small'
     *                              
     *    @return     stdClass result data or error number
     */
    public function readOrderLine(stdClass $params)
    {
        global $conf;
        
        if (!isset($this->db)) return CONNECTERROR;
        if (!isset($this->_user->rights->commande->lire)) return PERMISSIONERROR;
        dol_include_once('/extdirect/class/ExtDirectProduct.class.php');
        
    
        $results = array();
        $row = new stdClass;
        $order_id = 0;
        $photoSize = '';
        $onlyProduct = 1;
    
        if (isset($params->filter)) {
            foreach ($params->filter as $key => $filter) {
                if ($filter->property == 'id') $order_id=$filter->value; // deprecated
                if ($filter->property == 'order_id') $order_id=$filter->value;
                if ($filter->property == 'warehouse_id') $warehouse_id=$filter->value;
                if ($filter->property == 'photo_size' && !empty($filter->value)) $photoSize = $filter->value;
                if ($filter->property == 'multiprices_index'  && !empty($filter->value)) $multiprices_index = $filter->value;
                if ($filter->property == 'only_product') $onlyProduct=$filter->value;
            }
        }
    
        if ($order_id > 0) {
            $this->id=$order_id;
            $this->loadExpeditions();
            if (($result = $this->fetch_lines($onlyProduct)) < 0)  return ExtDirect::getDolError($result, $this->errors, $this->error);
            if (!$this->error) {
                foreach ($this->lines as $line) {
                	$isService = false;
                	(!$line->fk_product) ? $isFreeLine = true : $isFreeLine = false;
                    $myprod = new ExtDirectProduct($this->_user->login);
                    if (!$isFreeLine && ($result = $myprod->fetch($line->fk_product)) < 0) return $result;
                    if (ExtDirect::checkDolVersion() >= 3.5) {
                        if (!$isFreeLine && ($result = $myprod->load_stock('warehouseopen')) < 0) return $result;
                    } 
                    if ($line->product_type == 1) {
                    	$isService = true;
                    }
                    if ($isService || $isFreeLine || isset($warehouse_id) || ($myprod->stock_reel == 0)) {
                        if ($isService || $isFreeLine || $warehouse_id == -1) {
                            // get orderline with complete stock
                            $row = null;
                            $row->id = $line->rowid;
                            $row->origin_id = $line->fk_commande;
                            $row->origin_line_id = $line->rowid;
                            if (empty($line->label)) {
                            	if ($isFreeLine) {
                            		$row->label = $line->desc;
                            	} else {
                            		$row->label = $line->product_label;
                            	}
                            } else {
                                $row->label = $line->label;
                            } 
                            $row->description = $line->desc;
                            $row->product_id = $line->fk_product;
                            $row->ref = $line->product_ref;
                            $row->product_label = $line->product_label;
                            $row->product_desc = $line->product_desc;
                            $row->product_type = $line->product_type;
                            $row->barcode= $myprod->barcode?$myprod->barcode:'';
                            $row->qty_asked = $line->qty;
                            $row->tax_tx = $line->tva_tx;
                            $row->localtax1_tx = $line->localtax1_tx;
                            $row->localtax2_tx = $line->localtax2_tx;
                            $row->total_net = $line->total_ht;
                            $row->total_inc = $line->total_ttc;
                            $row->total_tax = $line->total_tva;
                            $row->total_localtax1 = $line->total_localtax1;
                            $row->total_localtax2 = $line->total_localtax2;
                            if (! empty($conf->global->PRODUIT_MULTIPRICES) && isset($multiprices_index)) {
			                    //! Arrays for multiprices
			                    $row->product_price=$myprod->multiprices[$multiprices_index];
			                    $row->product_price_ttc=$myprod->multiprices_ttc[$multiprices_index];
			                    $row->price_base_type=$myprod->multiprices_base_type[$multiprices_index];
			                } else {
			                	$row->product_price = $myprod->price;
			                	$row->product_price_ttc = $myprod->price_ttc;
			                	$row->price_base_type = $myprod->price_base_type;
			                }
                            $row->rang = $line->rang;
                            $row->price = $line->price;
                            $row->subprice = $line->subprice;
                            $row->reduction_percent = $line->remise_percent;
                            $this->expeditions[$line->rowid]?$row->qty_shipped = $this->expeditions[$line->rowid]:$row->qty_shipped = 0;
                            $row->stock = $myprod->stock_reel;
                            $row->total_stock = $row->stock;
                            if ($isService) {
                            	$row->warehouse_id = -1; // service is not stocked
                            } else if ($isFreeLine) {
                            	$row->warehouse_id = 0; // freeline is not in a specific stock location
                            } else {
                            	$row->warehouse_id = $warehouse_id;
                            }
                            $row->has_photo = 0;
                            if (!$isFreeLine && !empty($photoSize)) {
                                $myprod->fetchPhoto($row, $photoSize);
                            }
                            array_push($results, $row);
                        } else {
                            // get orderline with stock of warehouse
                            $row = null;
                            $row->id = $line->rowid.'_'.$warehouse_id;
                            $row->origin_id = $line->fk_commande;
                            $row->origin_line_id = $line->rowid;
                            if (empty($line->label)) {
                                $row->label = $line->product_label;
                            } else {
                                $row->label = $line->label;
                            }                           
                            $row->description = $line->desc;
                            $row->product_id = $line->fk_product;
                            $row->ref = $line->product_ref;
                            $row->product_label = $line->product_label;
                            $row->product_desc = $line->product_desc;
                            $row->product_type = $line->product_type;
                            $row->barcode= $myprod->barcode?$myprod->barcode:'';
                            $row->qty_asked = $line->qty;
                            $row->tax_tx = $line->tva_tx;
                            $row->localtax1_tx = $line->localtax1_tx;
                            $row->localtax2_tx = $line->localtax2_tx;
                            $row->total_net = $line->total_ht;
                            $row->total_inc = $line->total_ttc;
                            $row->total_tax = $line->total_tva;
                            $row->total_localtax1 = $line->total_localtax1;
                            $row->total_localtax2 = $line->total_localtax2;
                        	if (! empty($conf->global->PRODUIT_MULTIPRICES) && isset($multiprices_index)) {
			                    //! Arrays for multiprices
			                    $row->product_price=$myprod->multiprices[$multiprices_index];
			                    $row->product_price_ttc=$myprod->multiprices_ttc[$multiprices_index];
			                    $row->price_base_type=$myprod->multiprices_base_type[$multiprices_index];
			                } else {
			                	$row->product_price = $myprod->price;
			                	$row->product_price_ttc = $myprod->price_ttc;
			                	$row->price_base_type = $myprod->price_base_type;
			                }
                            $row->rang = $line->rang;
                            $row->price = $line->price;
                            $row->subprice = $line->subprice;
                            $row->reduction_percent = $line->remise_percent;
                            $this->expeditions[$line->rowid]?$row->qty_shipped = $this->expeditions[$line->rowid]:$row->qty_shipped = 0;
                            $row->stock = (float) $myprod->stock_warehouse[$warehouse_id]->real;
                            $row->total_stock = $myprod->stock_reel;
                            $row->warehouse_id = $warehouse_id;
                            $row->has_photo = 0;
                            if (!$isFreeLine && !empty($photoSize)) {
                                $myprod->fetchPhoto($row, $photoSize);
                            }
                            // split orderlines by batch
                            $row->has_batch = $myprod->status_batch;
                            if (empty($conf->productbatch->enabled)) {
                                array_push($results, $row);
                            } else {
                                if (($res = $myprod->fetchBatches($results, $row, $line->rowid, $warehouse_id, $myprod->stock_warehouse[$warehouse_id]->id)) < 0) return $res;
                            }
                        }                        
                    } else {
                        foreach ($myprod->stock_warehouse as $warehouse=>$stock_warehouse) {
							$row = null;
							$row->id = $line->rowid.'_'.$warehouse;
							$row->origin_id = $line->fk_commande;
							$row->origin_line_id = $line->rowid;
							if (empty($line->label)) {
								$row->label = $line->product_label;
							} else {
								$row->label = $line->label;
							} 
							$row->description = $line->desc;
							$row->product_id = $line->fk_product;
							$row->ref = $line->product_ref;
							$row->product_label = $line->product_label;
							$row->product_desc = $line->product_desc;
							$row->barcode= $myprod->barcode?$myprod->barcode:'';
							$row->product_type = $line->product_type;
							// limit qty asked to stock qty
							$row->qty_asked = $line->qty;
							$row->tax_tx = $line->tva_tx;
							$row->localtax1_tx = $line->localtax1_tx;
							$row->localtax2_tx = $line->localtax2_tx;
							$row->total_net = $line->total_ht;
							$row->total_inc = $line->total_ttc;
							$row->total_tax = $line->total_tva;
							$row->total_localtax1 = $line->total_localtax1;
							$row->total_localtax2 = $line->total_localtax2;
							if (! empty($conf->global->PRODUIT_MULTIPRICES) && isset($multiprices_index)) {
								//! Arrays for multiprices
								$row->product_price=$myprod->multiprices[$multiprices_index];
								$row->product_price_ttc=$myprod->multiprices_ttc[$multiprices_index];
								$row->price_base_type=$myprod->multiprices_base_type[$multiprices_index];
							} else {
								$row->product_price = $myprod->price;
								$row->product_price_ttc = $myprod->price_ttc;
								$row->price_base_type = $myprod->price_base_type;
							}
							$row->rang = $line->rang;
							$row->price = $line->price;
							$row->subprice = $line->subprice;
							$row->reduction_percent = $line->remise_percent;
							$this->expeditions[$line->rowid]?$row->qty_shipped = $this->expeditions[$line->rowid]:$row->qty_shipped = 0;
							$row->stock = (float) $stock_warehouse->real;
							$row->total_stock = $myprod->stock_reel;
							$row->warehouse_id = $warehouse;
							$row->has_photo = 0;
							if (!empty($photoSize)) {
								$myprod->fetchPhoto($row, $photoSize);
							}
							// split orderlines by batch
							$row->has_batch = $myprod->status_batch;
							if (empty($conf->productbatch->enabled)) {
								array_push($results, $row);
							} else {
								if (($res = $myprod->fetchBatches($results, $row, $line->rowid, $warehouse, $stock_warehouse->id)) < 0) return $res;
							}
                        }
                    }
                }
            } else {
                return 0;
            }
        }
        return $results;
    }
    
    /**
     * Ext.direct method to Create Orderlines
     *
     * @param unknown_type $param object or object array with product model(s)
     * @return Ambigous <multitype:, unknown_type>|unknown
     */
    public function createOrderLine($param) 
    {
        global $conf, $mysoc;
        
        if (!isset($this->db)) return CONNECTERROR;
        if (!isset($this->_user->rights->commande->creer)) return PERMISSIONERROR;
        $orderLine = new OrderLine($this->db);
        
        // set global $mysoc required for price calculation
        $mysoc = new Societe($this->db);
		$mysoc->setMysoc($conf);
		
        $notrigger=0;
        $paramArray = ExtDirect::toArray($param);
    
        foreach ($paramArray as &$params) {
            // prepare fields
            $this->prepareOrderLineFields($params, $orderLine);
            if (($result = $this->fetch($orderLine->fk_commande)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
            $this->fetch_thirdparty();
            if (! empty($conf->global->PRODUIT_MULTIPRICES) && ! empty($this->thirdparty->price_level)) {
            	if (!empty($this->thirdparty->tva_assuj)) {
                	$tva_tx = $orderLine->tva_tx;
                } else {
                	$tva_tx = 0;
               	}
				$tva_npr = 0;
            } else {
            	$tva_tx = get_default_tva($mysoc, $this->thirdparty, $orderLine->fk_product);
				$tva_npr = get_default_npr($mysoc, $this->thirdparty, $orderLine->fk_product);
            }
            
            $info_bits = 0;
			if ($tva_npr) $info_bits |= 0x01;
			if (!empty($params->product_price) || !empty($params->product_price_ttc)) {
				// when product_price is available, use product price for calculating unit price
				if ($orderLine->price_base_type == 'TTC') {
					$tabprice = calcul_price_total($orderLine->qty, $params->product_price_ttc, $orderLine->remise_percent, $tva_tx, $orderLine->total_localtax1, $orderLine->total_localtax2, 0, $orderLine->price_base_type, $info_bits, $orderLine->product_type);	
					$pu_ht = $tabprice[3];
					$pu_ttc = $tabprice[5];
				} else {
					$tabprice = calcul_price_total($orderLine->qty, $params->product_price, $orderLine->remise_percent, $tva_tx, $orderLine->total_localtax1, $orderLine->total_localtax2, 0, $orderLine->price_base_type, $info_bits, $orderLine->product_type);	
					$pu_ht = $tabprice[3];
					$pu_ttc = $tabprice[5];
				}
			} else {
				$tabprice = calcul_price_total($orderLine->qty, $orderLine->subprice, $orderLine->remise_percent, $tva_tx, $orderLine->total_localtax1, $orderLine->total_localtax2, 0, 'HT', $info_bits, $orderLine->product_type);	
				$pu_ht = $tabprice[3];
				$pu_ttc = $tabprice[5];
			}			
			
            if (ExtDirect::checkDolVersion() >= 3.5) {
                $this->id = $orderLine->fk_commande;
                if (($result = $this->addline(
                    $orderLine->desc,
                    $pu_ht,
                    $orderLine->qty,
                    $tva_tx,
                    $orderLine->localtax1_tx,
                    $orderLine->localtax2_tx,
                    $orderLine->fk_product,
                    $orderLine->remise_percent,
                    $info_bits,
                    $orderLine->fk_remise_except,
                    $orderLine->price_base_type,
                    $pu_ttc,
                    $orderLine->date_start,
                    $orderLine->date_end,
                    $orderLine->product_type,
                    $orderLine->rang,
                    $orderLine->special_code,
                    $orderLine->fk_parent_line,
                    $orderLine->fk_fournprice,
                    $orderLine->pa_ht,
                    $orderLine->label
                )) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                $params->id=$result;
            } else {
                if (($result = $this->addline(
                    $orderLine->fk_commande,
                    $orderLine->desc,
                    $pu_ht,
                    $orderLine->qty,
                    $tva_tx,
                    $orderLine->localtax1_tx,
                    $orderLine->localtax2_tx,
                    $orderLine->fk_product,
                    $orderLine->remise_percent,
                    $info_bits,
                    $orderLine->fk_remise_except,
                    $orderLine->price_base_type,
                    $pu_ttc,
                    $orderLine->date_start,
                    $orderLine->date_end,
                    $orderLine->product_type,
                    $orderLine->rang,
                    $orderLine->special_code,
                    $orderLine->fk_parent_line,
                    $orderLine->fk_fournprice,
                    $orderLine->pa_ht,
                    $orderLine->label
                )) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
            }
        }
    
        if (is_array($param)) {
            return $paramArray;
        } else {
            return $params;
        }
    }
    
    /**
     * Ext.direct method to update orderlines
     *
     * @param unknown_type $param object or object array with order model(s)
     * @return Ambigous <multitype:, unknown_type>|unknown
     */
    public function updateOrderLine($param) 
    {
        global $conf, $mysoc;
        
        if (!isset($this->db)) return CONNECTERROR;
        if (!isset($this->_user->rights->commande->creer)) return PERMISSIONERROR;
        $orderlineUpdated = false;
        
        // set global $mysoc required for price calculation
        $mysoc = new Societe($this->db);
		$mysoc->setMysoc($conf);
		
        $paramArray = ExtDirect::toArray($param);
    
        foreach ($paramArray as &$params) {
            
            if (($this->id=$params->origin_id) > 0) {
                // get old orderline
                if (($result = $this->fetch($this->id)) < 0)  return ExtDirect::getDolError($result, $this->errors, $this->error);
                if (($result = $this->fetch_lines()) < 0)  return ExtDirect::getDolError($result, $this->errors, $this->error);
                $this->fetch_thirdparty();         
				
                if (!$this->error) {
                    foreach ($this->lines as $orderLine) {
                        if ($orderLine->rowid == $params->origin_line_id) {
                            // update fields
                            $this->prepareOrderLineFields($params, $orderLine);
                        	if (! empty($conf->global->PRODUIT_MULTIPRICES) && ! empty($this->thirdparty->price_level)) {
			                	if (!empty($this->thirdparty->tva_assuj)) {
			                		$tva_tx = $orderLine->tva_tx;
			                	} else {
			                		$tva_tx = 0;
			                	}
								$tva_npr = 0;
				            } else {
				            	$tva_tx = get_default_tva($mysoc, $this->thirdparty, $orderLine->fk_product);
								$tva_npr = get_default_npr($mysoc, $this->thirdparty, $orderLine->fk_product);
				            }
				            
				            $info_bits = 0;
							if ($tva_npr) {
								$info_bits |= 0x01;
							} else {
								$info_bits = $orderLine->info_bits;
							}
							
                            if (($result = $this->updateline(
                                $orderLine->rowid, 
                                $orderLine->desc, 
                                $orderLine->subprice,
                                $orderLine->qty, 
                                $orderLine->remise_percent, 
                                $tva_tx, 
                                $orderLine->total_localtax1,
                                $orderLine->total_localtax2, 
                                'HT', 
                                $info_bits, 
                                $orderLine->date_start, 
                                $orderLine->date_end, 
                                $orderLine->product_type, 
                                $orderLine->fk_parent_line, 
                                $orderLine->skip_update_total, 
                                $orderLine->fk_fournprice, 
                                $orderLine->pa_ht, 
                                $orderLine->label, 
                                $orderLine->special_code
                            )
                            ) < 0)  return ExtDirect::getDolError($result, $this->errors, $this->error);
                            $orderlineUpdated = true;
                        }
                    }
                    if (!$orderlineUpdated) return UPTADEERROR;
                }           
            } else {
                return PARAMETERERROR;
            }
        }
        if (is_array($param)) {
            return $paramArray;
        } else {
            return $params;
        }
    }
    
    /**
     * Ext.direct method to destroy orderlines
     *
     * @param unknown_type $param object or object array with order model(s)
     * @return Ambigous <multitype:, unknown_type>|unknown
     */
    public function destroyOrderLine($param) 
    {
        if (!isset($this->db)) return CONNECTERROR;
        if (!isset($this->_user->rights->commande->supprimer)) return PERMISSIONERROR;
        $paramArray = ExtDirect::toArray($param);
    
        foreach ($paramArray as &$params) {
            // prepare fields
            if ($params->origin_line_id) {
                // delete 
                $this->id = $params->origin_id;
                if (ExtDirect::checkDolVersion(0,'5.0','')) {
                	if (($result = $this->deleteline($this->_user, $params->origin_line_id)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                } else {
                	if (($result = $this->deleteline($params->origin_line_id)) < 0) return ExtDirect::getDolError($result, $this->errors, $this->error);
                }
            } else {
                return PARAMETERERROR;
            }
        }
    
        if (is_array($param)) {
            return $paramArray;
        } else {
            return $params;
        }
    }
    
    /**
     * private method to copy order fields into dolibarr object
     *
     * @param stdclass $params object with fields
     * @param stdclass $orderLine object
     * @return null
     */
    private function prepareOrderLineFields($params,$orderLine) 
    {
        isset($params->origin_line_id) ? ( $orderLine->rowid= $params->origin_line_id) : null;
        isset($params->origin_id) ? ( $orderLine->fk_commande= $params->origin_id) : null;
        isset($params->product_id) ? ( $orderLine->fk_product = $params->product_id) : null;
        isset($params->subprice) ? ( $orderLine->subprice = $params->subprice) : null;
        isset($params->product_tax) ? ( $orderLine->tva_tx = $params->product_tax) : null;
        isset($params->description) ? ( $orderLine->desc = $params->description) : null;
        isset($params->qty_asked) ? ( $orderLine->qty = $params->qty_asked) : null;
        isset($params->reduction_percent) ? ($orderLine->remise_percent = $params->reduction_percent) : null;
        isset($params->tax_tx) ? ($orderLine->tva_tx = $params->tax_tx) : null;
        isset($params->localtax1_tx) ? ($orderLine->localtax1_tx = $params->localtax1_tx) : null;
        isset($params->localtax2_tx) ? ($orderLine->localtax2_tx = $params->localtax2_tx) : null;
        isset($params->product_type) ? ($orderLine->product_type = $params->product_type) : null;
        isset($params->rang) ? ($orderLine->rang = $params->rang) : null;
        isset($params->label) ? ($orderLine->label = $params->label) : null;
        isset($params->price) ? ($orderLine->price = $params->price) : ($orderLine->price ? null : $orderLine->price = 0);
        isset($params->price_base_type) ? ($orderLine->price_base_type = $params->price_base_type) : $orderLine->price_base_type = 'HT';
    }
}
