<?php
function Assets_NFTcontract_response_getInfo ($params) {
	$req = array_merge($_REQUEST, $params);
	Q_Valid::requireFields(array("address", "chainId"), $req, true);
	$contractAddress = $req["address"];
	$chainId = $req["chainId"];
	$title = Q::ifset($req, "title", null);
	$publisherId = Q::ifset($req, "publisherId", null);
	$streamName = Q::ifset($req, "streamName", null);
	$texts = Q_Text::get('Assets/content')['NFT']['contract'];
	$chain = Assets_NFT::getChains($chainId);
	$pathABI = Q::ifset($request, 'pathABI', "Assets/templates/R1/NFT/contract");

	$name = $symbol = null;
	if (!$title || $title == "false") {
		$name = Users_Web3::execute($pathABI, $contractAddress, "name", array(), $chainId);
		$symbol = Users_Web3::execute($pathABI, $contractAddress, "symbol", array(), $chainId);
		if ($publisherId && $streamName) {
			// This used to fetch the stream AS ITS PUBLISHER and save a new
			// title for any caller, logged in or not, from a GET slot. Now the
			// caller must be logged in, present the session nonce, and be able
			// to edit the stream (ro#1083).
			$user = Users::loggedInUser(true);
			Q_Valid::nonce(true);
			$stream = Streams_Stream::fetch($user->id, $publisherId, $streamName, true);
			if (!$stream->testWriteLevel('edit')) {
				throw new Users_Exception_NotAuthorized();
			}
			$stream->title = Q::interpolate($texts["ContractName"], array(
				"contractName" => $name,
				"contractSymbol" => $symbol,
				"chainNetwork" => $chain["name"]
			));
			$stream->save();
		}
	}
	$owner = Users_Web3::execute($pathABI, $contractAddress, "owner", array(), $chainId);

	return compact("name", "symbol", "owner");
}