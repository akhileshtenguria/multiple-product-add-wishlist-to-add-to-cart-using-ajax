<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Codazon\WishlistOverride\Controller\Index;


use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Checkout\Model\Cart as CheckoutCart;
use Magento\Checkout\Helper\Cart as CartHelper;
use Magento\Framework\Controller\Result\JsonFactory;
// use Magento\Catalog\Model\Product;
// use Magento\Checkout\Model\Cart;
use Magento\Catalog\Helper\Product;
use Magento\Framework\Escaper;
use Magento\Framework\App\ObjectManager;
use Magento\Wishlist\Controller\WishlistProviderInterface;
use Magento\Wishlist\Helper\Data;
use Magento\Wishlist\Model\Item\OptionFactory;
use Magento\Wishlist\Model\ItemFactory;
use Magento\Wishlist\Model\LocaleQuantityProcessor;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
class Index extends Action
{
    private $objectManager;

    public function __construct(Context $context,
        WishlistProviderInterface $wishlistProvider,
        LocaleQuantityProcessor $quantityProcessor,
        ItemFactory $itemFactory,
        CheckoutCart $cart,
        OptionFactory $optionFactory,
        Product $productHelper,
        Escaper $escaper,
        Data $helper,
        CartHelper $cartHelper,
        Validator $formKeyValidator,
        ?CookieManagerInterface $cookieManager = null,
        ?CookieMetadataFactory $cookieMetadataFactory = null)
    {
        $this->wishlistProvider = $wishlistProvider;
        $this->quantityProcessor = $quantityProcessor;
        $this->itemFactory = $itemFactory;
        $this->cart = $cart;
        $this->optionFactory = $optionFactory;
        $this->productHelper = $productHelper;
        $this->escaper = $escaper;
        $this->helper = $helper;
        $this->cartHelper = $cartHelper;
        $this->formKeyValidator = $formKeyValidator;
        $this->cookieManager = $cookieManager ?: ObjectManager::getInstance()->get(CookieManagerInterface::class);
        $this->cookieMetadataFactory = $cookieMetadataFactory ?:
            ObjectManager::getInstance()->get(CookieMetadataFactory::class);
        parent::__construct($context);
    }

    public function execute()
    { 

         $success=false;
        $data = $this->getRequest()->getPostValue();
        $qty = $this->getRequest()->getParam('qty');
        $array_qty = json_decode($qty,true);
        // $logger->info(print_r($qty,true));

        if($data['items']){
            $itemsIds = explode(",",$data['items']);
            // $logger->info('itemsIds print array');
            // $logger->info(print_r($itemsIds,true));
        foreach ($itemsIds as $key => $itemId) {
        $item = $this->itemFactory->create()->load($itemId);
        
        if (is_array($array_qty)) {
            if (isset($array_qty[$itemId])) {
                $qty = $array_qty[$itemId];
            } else {
                $qty = 1;
            }
        }
       
        $redirectUrl = $this->_url->getUrl('*/*');
        $configureUrl = $this->_url->getUrl(
            '*/*/configure/',
            [
                'id' => $item->getId(),
                'product_id' => $item->getProductId(),
            ]
        );
       
        if ($qty) {
            $item->setQty($qty);
        }
            
            $wishlist = $this->wishlistProvider->getWishlist($item->getWishlistId());

             try {
            $options = $this->optionFactory->create()->getCollection()->addItemFilter([$itemId]);
            $item->setOptions($options->getOptionsByItem($itemId));

            
            $buyRequest = $this->productHelper->addParamsToBuyRequest(
                $this->getRequest()->getParams(),
                ['current_config' => $item->getBuyRequest()]
            );
            
            $item->mergeBuyRequest($buyRequest);
            $item->addToCart($this->cart, true);
           

            if (!$this->cart->getQuote()->getHasError()) {
                $message = __(
                    'You added %1 to your shopping cart.',
                    $this->escaper->escapeHtml($item->getProduct()->getName())
                );
                $this->messageManager->addSuccessMessage($message);
                $success=true;
                $productsToAdd = [
                    [
                        'sku' => $item->getProduct()->getSku(),
                        'name' => $item->getProduct()->getName(),
                        'price' => $item->getProduct()->getFinalPrice(),
                        'qty' => $item->getQty(),
                    ]
                ];

              
                /** @var PublicCookieMetadata $publicCookieMetadata */
                $publicCookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setDuration(3600)
                    ->setPath('/')
                    ->setHttpOnly(false)
                    ->setSameSite('Strict');

                $this->cookieManager->setPublicCookie(
                    'add_to_cart',
                    \rawurlencode(\json_encode($productsToAdd)),
                    $publicCookieMetadata
                );
            }
            
            } catch (ProductException $e) {
                $success=false;
                $message= __('This product(s) is out of stock.');
                $this->messageManager->addErrorMessage(__('This product(s) is out of stock.'));
            } catch (LocalizedException $e) {
                $success=false;
                $message = $e->getMessage();
                $this->messageManager->addNoticeMessage($e->getMessage());
                $redirectUrl = $configureUrl;
            } catch (\Exception $e) {
                $success=false;
                $message=__("We can\'t add the item to the cart right now.");
                $this->messageManager->addExceptionMessage($e, __($e->getMessage(). ' We can\'t add the item to the cart right now.'));
            }

 
    }
            $this->cart->save()->getQuote()->collectTotals();
            $wishlist->save();
        if ($this->getRequest()->isAjax()) {
            /** @var Json $resultJson */
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $resultJson->setData(['backUrl' => $redirectUrl]);
            echo $resultJson;
        }

         // $redirectUrl; die; 
    }else{
        $success=false;
                $message=__("No any product selected for add to cart.");
        $this->messageManager->addErrorMessage(__('No any product selected for add to cart.'));
    }
    $redirectUrl = $this->_url->getUrl('*/*');
    if($success==true){
        $response = ['status' => 'success', 'message' => 'Data received successfully','redirectUrl'=>$redirectUrl];
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        return $resultJson->setData($response);
    }else{
        $response = ['status' => 'error', 'message' =>$message,'redirectUrl'=>$redirectUrl];

        // $response = ['status' => 'success', 'message' => 'Data received successfully','redirectUrl'=>$redirectUrl];
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        return $resultJson->setData($response);
    }

    }
}

